<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE prc_revisions (
                id bigint GENERATED ALWAYS AS IDENTITY,
                procurement_id bigint NOT NULL,
                revision_number int NOT NULL,
                source_edition text NULL,
                change_reason text NULL,
                snapshot jsonb NOT NULL,
                snapshot_hash text NOT NULL,
                source_updated_at timestamptz NULL,
                detected_at timestamptz NOT NULL,
                crawler_run_id bigint NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                PRIMARY KEY (id, detected_at)
            ) PARTITION BY RANGE (detected_at)
            SQL);

        DB::statement('CREATE INDEX prc_revisions_procurement_id_index ON prc_revisions (procurement_id)');
        DB::statement(
            'CREATE UNIQUE INDEX prc_revisions_procurement_revision_detected_unique '
            .'ON prc_revisions (procurement_id, revision_number, detected_at)',
        );
        DB::statement(
            'ALTER TABLE prc_revisions ADD CONSTRAINT prc_revisions_procurement_id_foreign '
            .'FOREIGN KEY (procurement_id) REFERENCES prc_procurements (id)',
        );

        $this->bootstrapPartitions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prc_revisions CASCADE');
    }

    private function bootstrapPartitions(): void
    {
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = 'prc_revisions_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF prc_revisions FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
};
