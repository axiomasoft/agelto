<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE prc_fetches ALTER COLUMN crawler_run_id DROP NOT NULL');
        DB::statement('ALTER TABLE prc_fetches ADD COLUMN stage text NULL');
        DB::statement('ALTER TABLE prc_fetches ADD COLUMN procurement_id bigint NULL');
        DB::statement('ALTER TABLE prc_fetches ADD COLUMN wait_ms int NULL');
        DB::statement('CREATE INDEX prc_fetches_procurement_id_index ON prc_fetches (procurement_id)');
        DB::statement('ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_procurement_id_foreign FOREIGN KEY (procurement_id) REFERENCES prc_procurements (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prc_fetches DROP CONSTRAINT prc_fetches_procurement_id_foreign');
        DB::statement('DROP INDEX prc_fetches_procurement_id_index');
        DB::statement('ALTER TABLE prc_fetches DROP COLUMN wait_ms, DROP COLUMN procurement_id, DROP COLUMN stage');
        DB::statement('ALTER TABLE prc_fetches ALTER COLUMN crawler_run_id SET NOT NULL');
    }
};
