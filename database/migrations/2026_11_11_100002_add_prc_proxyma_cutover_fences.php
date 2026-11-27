<?php

declare(strict_types=1);

use App\Models\Procurement\Network\SourceTarget;
use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\LegacyNetworkBudget as NetworkBudget;
use App\Models\Procurement\Proxyma\LegacyNetworkDemand as NetworkDemand;
use App\Models\Procurement\Proxyma\LegacyNetworkPermit as NetworkPermit;
use App\Models\Procurement\Proxyma\LegacyNetworkRoute as NetworkRoute;
use App\Models\Procurement\Proxyma\LegacyNetworkRouteProfile as NetworkRouteProfile;
use App\Models\Procurement\Proxyma\LegacyPermitScope;
use App\Models\Procurement\Proxyma\LegacyProfileMember;
use App\Models\Procurement\Proxyma\LegacyProxy as Proxy;
use App\Models\Procurement\Proxyma\LegacyRouteHealth as RouteHealth;
use App\Models\Procurement\Proxyma\LegacyRouteTargetHealth as RouteTargetHealth;
use App\Models\Procurement\Proxyma\LegacyTargetHealth as TargetHealth;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $selector = (new NetworkAuthority)->getTable();
        $journal = (new CutoverJournal)->getTable();
        Schema::table($selector, function (Blueprint $table): void {
            $table->boolean('paused')->default(false);
            $table->boolean('legacy_frozen')->default(false);
        });
        // SQL trigger closes stale/bypassing writers too. It never infers not-sent from expiry.
        DB::unprepared("CREATE OR REPLACE FUNCTION prc_pxm_legacy_write_fence() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE s record;
            BEGIN
                SELECT * INTO STRICT s FROM {$selector} WHERE id='network' FOR SHARE;
                IF s.authority <> 'legacy' OR s.legacy_frozen THEN
                    RAISE EXCEPTION 'legacy authority frozen';
                END IF;
                IF s.paused AND (TG_ARGV[0]='issuance' AND TG_OP='INSERT') THEN
                    RAISE EXCEPTION 'legacy issuance paused';
                END IF;
                IF s.paused AND TG_ARGV[0]='permit' THEN
                    IF TG_OP='INSERT' OR (TG_OP='UPDATE' AND OLD.authorized_at IS NULL AND NEW.authorized_at IS NOT NULL) THEN
                        RAISE EXCEPTION 'legacy authorization paused';
                    END IF;
                END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END \$\$");
        foreach ([new Proxy, new NetworkBudget, new NetworkDemand, new NetworkPermit, new NetworkRoute,
            new NetworkRouteProfile, new RouteHealth, new RouteTargetHealth, new SourceTarget, new TargetHealth,
            new LegacyPermitScope, new LegacyProfileMember] as $model) {
            $mode = match ($model::class) {
                NetworkDemand::class => 'issuance', NetworkPermit::class => 'permit', default => 'state',
            };
            DB::unprepared("CREATE TRIGGER prc_pxm_legacy_fence BEFORE INSERT OR UPDATE OR DELETE ON {$model->getTable()}
                FOR EACH ROW EXECUTE FUNCTION prc_pxm_legacy_write_fence('{$mode}')");
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION prc_pxm_new_claim_fence() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE s record; marked integer;
            BEGIN
                IF NEW.authorized_at IS NOT NULL AND (TG_OP='INSERT' OR OLD.authorized_at IS NULL) THEN
                    SELECT * INTO STRICT s FROM {$selector} WHERE id='network' FOR SHARE;
                    IF s.authority <> 'proxyma' OR s.paused THEN RAISE EXCEPTION 'package authorization paused'; END IF;
                    UPDATE {$journal} SET first_authorized_at=COALESCE(first_authorized_at,clock_timestamp())
                        WHERE id=s.cutover_id AND generation=s.generation AND phase IN ('switched','accepted');
                    GET DIAGNOSTICS marked = ROW_COUNT;
                    IF marked <> 1 THEN RAISE EXCEPTION 'cutover journal not switched'; END IF;
                END IF;
                RETURN NEW;
            END \$\$");
        DB::unprepared("CREATE OR REPLACE FUNCTION prc_pxm_irreversible() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.first_authorized_at IS NOT NULL THEN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'irreversible claim marker'; END IF;
                    IF NEW.first_authorized_at IS DISTINCT FROM OLD.first_authorized_at OR NEW.generation <> OLD.generation THEN
                        RAISE EXCEPTION 'irreversible claim marker';
                    END IF;
                END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER prc_pxm_irreversible BEFORE UPDATE OR DELETE ON {$journal}
                FOR EACH ROW EXECUTE FUNCTION prc_pxm_irreversible()");
        DB::unprepared("CREATE OR REPLACE FUNCTION prc_pxm_no_reverse() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.authority='proxyma' AND (NEW.authority <> 'proxyma' OR NEW.cutover_id IS DISTINCT FROM OLD.cutover_id)
                    AND EXISTS (SELECT 1 FROM {$journal} WHERE id=OLD.cutover_id AND first_authorized_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'first new claim requires roll-forward';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER prc_pxm_no_reverse BEFORE UPDATE ON {$selector}
                FOR EACH ROW EXECUTE FUNCTION prc_pxm_no_reverse()");
    }
};
