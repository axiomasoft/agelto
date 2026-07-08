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
            CREATE TABLE prc_operation_logs (
                id bigint GENERATED ALWAYS AS IDENTITY,
                level text NOT NULL,
                stage text NULL,
                source text NULL,
                entity_type text NULL,
                entity_id bigint NULL,
                message text NOT NULL,
                context jsonb NULL,
                trace text NULL,
                created_at timestamptz NOT NULL,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);

        DB::statement('CREATE INDEX prc_operation_logs_entity_index ON prc_operation_logs (entity_type, entity_id)');

        $this->bootstrapPartitions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prc_operation_logs CASCADE');
    }

    private function bootstrapPartitions(): void
    {
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = 'prc_operation_logs_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF prc_operation_logs FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
};
