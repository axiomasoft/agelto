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
            CREATE TABLE prc_fetches (
                id bigint GENERATED ALWAYS AS IDENTITY,
                crawler_run_id bigint NOT NULL,
                method text NOT NULL,
                request_params jsonb NULL,
                artifact_ref text NULL,
                http_status int NULL,
                duration_ms int NULL,
                rows_total int NULL,
                rows_new int NULL,
                rows_updated int NULL,
                dedup_signature text NULL,
                proxy_id bigint NULL,
                fetched_at timestamptz NOT NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                PRIMARY KEY (id, fetched_at)
            ) PARTITION BY RANGE (fetched_at)
            SQL);

        DB::statement('CREATE INDEX prc_fetches_crawler_run_id_index ON prc_fetches (crawler_run_id)');
        DB::statement('CREATE INDEX prc_fetches_proxy_id_index ON prc_fetches (proxy_id)');
        DB::statement(
            'ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_crawler_run_id_foreign '
            .'FOREIGN KEY (crawler_run_id) REFERENCES prc_crawler_runs (id)',
        );
        DB::statement(
            'ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_proxy_id_foreign '
            .'FOREIGN KEY (proxy_id) REFERENCES prc_proxies (id)',
        );

        $this->bootstrapPartitions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prc_fetches CASCADE');
    }

    private function bootstrapPartitions(): void
    {
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = 'prc_fetches_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF prc_fetches FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
};
