<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Network\SourceTarget;
use App\Models\Procurement\Process\WorkAttempt;
use App\Models\Procurement\Proxyma\BindingLink;
use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\LegacyNetworkRouteProfile as NetworkRouteProfile;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\OwnerLink;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\Procurement\Proxyma\TargetLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new TargetLink)->getTable(), function (Blueprint $table): void {
            $table->uuid('source_target_id')->primary();
            $table->foreign('source_target_id')->references('id')->on((new SourceTarget)->getTable());
            $table->text('tenant_key');
            $table->uuid('target_id');
            $table->bigInteger('source_revision');
            $table->unique(['tenant_key', 'target_id'], 'prc_pxm_target_identity');
        });
        Schema::create((new ProfileLink)->getTable(), function (Blueprint $table): void {
            $table->uuid('source_profile_id');
            $table->foreign('source_profile_id')->references('id')->on((new NetworkRouteProfile)->getTable());
            $table->bigInteger('source_version');
            $table->text('tenant_key');
            $table->uuid('profile_id');
            $table->bigInteger('profile_version');
            $table->primary(['source_profile_id', 'source_version'], 'prc_pxm_profile_source');
            $table->unique(['tenant_key', 'profile_id', 'profile_version'], 'prc_pxm_profile_identity');
        });
        Schema::create((new BindingLink)->getTable(), function (Blueprint $table): void {
            $table->bigInteger('crawler_id');
            $table->foreign('crawler_id')->references('id')->on((new Crawler)->getTable());
            $table->text('slot');
            $table->text('tenant_key');
            $table->uuid('binding_id');
            $table->primary(['crawler_id', 'slot'], 'prc_pxm_binding_source');
            $table->unique(['tenant_key', 'binding_id'], 'prc_pxm_binding_identity');
        });
        Schema::create((new OwnerLink)->getTable(), function (Blueprint $table): void {
            $table->text('tenant_key');
            $table->text('owner_type');
            $table->uuid('owner_id');
            $table->bigInteger('generation');
            $table->uuid('operation_id');
            $table->uuid('parent_attempt_id')->nullable();
            $table->foreign('parent_attempt_id')->references('id')->on((new WorkAttempt)->getTable());
            $table->primary(['tenant_key', 'owner_type', 'owner_id', 'generation'], 'prc_pxm_owner_source');
            $table->unique(['tenant_key', 'operation_id'], 'prc_pxm_owner_identity');
        });
        Schema::create((new CutoverJournal)->getTable(), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('source_revision');
            $table->text('target_revision');
            $table->text('phase');
            $table->bigInteger('generation');
            $table->timestampTz('first_authorized_at')->nullable();
            $table->jsonb('checkpoint')->default('{}');
            $table->unique(['source_revision', 'target_revision', 'generation'], 'prc_pxm_journal_identity');
        });
        Schema::create((new NetworkAuthority)->getTable(), function (Blueprint $table): void {
            $table->text('id')->primary();
            $table->text('authority')->default('legacy');
            $table->bigInteger('generation');
            $table->uuid('cutover_id')->nullable();
            $table->foreign('cutover_id')->references('id')->on((new CutoverJournal)->getTable());
        });
        DB::table((new NetworkAuthority)->getTable())->insert(['id' => 'network', 'authority' => 'legacy', 'generation' => 1]);
        foreach ([
            (new TargetLink)->getTable() => 'source_revision > 0',
            (new ProfileLink)->getTable() => 'source_version > 0 AND profile_version > 0',
            (new BindingLink)->getTable() => "slot IN ('listing','detail','status_refresh','download','reference','llm')",
            (new OwnerLink)->getTable() => "generation > 0 AND owner_type IN ('process_attempt','outbound_operation')",
            (new CutoverJournal)->getTable() => "generation > 0 AND phase IN ('prepared','draining','verified','switched','accepted')",
            (new NetworkAuthority)->getTable() => "id = 'network' AND generation > 0 AND authority IN ('legacy','proxyma') AND (authority = 'legacy' OR cutover_id IS NOT NULL)",
        ] as $table => $check) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_valid CHECK ({$check})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists((new NetworkAuthority)->getTable());
        Schema::dropIfExists((new CutoverJournal)->getTable());
        Schema::dropIfExists((new OwnerLink)->getTable());
        Schema::dropIfExists((new BindingLink)->getTable());
        Schema::dropIfExists((new ProfileLink)->getTable());
        Schema::dropIfExists((new TargetLink)->getTable());
    }
};
