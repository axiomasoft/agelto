<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.6: один журнал fetch для всех стадий; crawler_run nullable, `stage`, `procurement_id` и
 * `wait_ms` описывают листинг, карточку и документ. P7.5 F-02: FK к run/proxy сохраняют
 * прежний `NO ACTION` явно; FK к procurement также остаётся `NO ACTION`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE prc_fetches (
                id bigint GENERATED ALWAYS AS IDENTITY,
                crawler_run_id bigint NULL,
                stage text NULL,
                procurement_id bigint NULL,
                method text NOT NULL,
                request_params jsonb NULL,
                artifact_ref text NULL,
                http_status int NULL,
                duration_ms int NULL,
                wait_ms int NULL,
                rows_total int NULL,
                rows_new int NULL,
                rows_updated int NULL,
                rows_parsed int NULL,
                rows_errors int NULL,
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
        DB::statement('CREATE INDEX prc_fetches_procurement_id_index ON prc_fetches (procurement_id)');
        DB::statement(
            'ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_crawler_run_id_foreign '
            .'FOREIGN KEY (crawler_run_id) REFERENCES prc_crawler_runs (id) ON DELETE NO ACTION',
        );
        DB::statement(
            'ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_proxy_id_foreign '
            .'FOREIGN KEY (proxy_id) REFERENCES prc_proxies (id) ON DELETE NO ACTION',
        );
        DB::statement(
            'ALTER TABLE prc_fetches ADD CONSTRAINT prc_fetches_procurement_id_foreign '
            .'FOREIGN KEY (procurement_id) REFERENCES prc_procurements (id) ON DELETE NO ACTION',
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
