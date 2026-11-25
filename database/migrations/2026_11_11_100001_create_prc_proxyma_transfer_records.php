<?php

declare(strict_types=1);

use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\EndpointLink;
use App\Models\Procurement\Proxyma\LegacyProxy as Proxy;
use App\Models\Procurement\Proxyma\StateArchive;
use App\Models\Procurement\Proxyma\TransferRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new EndpointLink)->getTable(), function (Blueprint $table): void {
            $table->bigInteger('proxy_id')->primary();
            $table->foreign('proxy_id')->references('id')->on((new Proxy)->getTable());
            $table->text('tenant_key');
            $table->uuid('endpoint_id');
            $table->unique(['tenant_key', 'endpoint_id']);
        });
        Schema::create((new TransferRecord)->getTable(), function (Blueprint $table): void {
            $table->text('target_table');
            $table->text('target_key');
            $table->uuid('cutover_id');
            $table->foreign('cutover_id')->references('id')->on((new CutoverJournal)->getTable());
            $table->jsonb('identity');
            $table->jsonb('imported');
            $table->char('checksum', 64);
            $table->primary(['target_table', 'target_key']);
        });
        Schema::create((new StateArchive)->getTable(), function (Blueprint $table): void {
            $table->text('source_table');
            $table->text('source_key');
            $table->jsonb('payload');
            $table->text('mapped_state')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->primary(['source_table', 'source_key']);
        });
        $records = (new TransferRecord)->getTable();
        $archive = (new StateArchive)->getTable();
        DB::statement("ALTER TABLE {$records} ADD CHECK (checksum ~ '^[0-9a-f]{64}$' AND jsonb_typeof(identity)='object' AND jsonb_typeof(imported)='object')");
        DB::statement("ALTER TABLE {$archive} ADD CHECK (mapped_state IS NULL OR
            (mapped_state IN ('cancelled_before_send','expired_before_send') AND authorized_at IS NULL) OR
            (mapped_state IN ('completed','unknown_after_authorization') AND authorized_at IS NOT NULL))");
        DB::unprepared("CREATE OR REPLACE FUNCTION prc_proxyma_transfer_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'immutable transfer evidence' USING ERRCODE='23514'; END \$\$;
            CREATE TRIGGER prc_pxm_transfer_immutable BEFORE UPDATE OR DELETE ON {$records}
                FOR EACH ROW EXECUTE FUNCTION prc_proxyma_transfer_immutable();
            CREATE TRIGGER prc_pxm_archive_immutable BEFORE UPDATE OR DELETE ON {$archive}
                FOR EACH ROW EXECUTE FUNCTION prc_proxyma_transfer_immutable();");
    }

    public function down(): void
    {
        Schema::dropIfExists((new StateArchive)->getTable());
        Schema::dropIfExists((new TransferRecord)->getTable());
        Schema::dropIfExists((new EndpointLink)->getTable());
        DB::statement('DROP FUNCTION IF EXISTS prc_proxyma_transfer_immutable()');
    }
};
