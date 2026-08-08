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
            CREATE TABLE prc_events (
                id bigint GENERATED ALWAYS AS IDENTITY,
                procurement_id bigint NOT NULL,
                revision_id bigint NULL,
                event_type text NOT NULL,
                entity_ref jsonb NULL,
                field text NULL,
                old_value text NULL,
                new_value text NULL,
                payload jsonb NULL,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
            SQL);

        DB::statement('CREATE INDEX prc_events_procurement_occurred_index ON prc_events (procurement_id, occurred_at)');
        DB::statement('CREATE INDEX prc_events_type_occurred_index ON prc_events (event_type, occurred_at)');
        DB::statement(
            'ALTER TABLE prc_events ADD CONSTRAINT prc_events_procurement_id_foreign '
            .'FOREIGN KEY (procurement_id) REFERENCES prc_procurements (id) ON DELETE RESTRICT',
        );

        $this->bootstrapPartitions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prc_events CASCADE');
    }

    private function bootstrapPartitions(): void
    {
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = 'prc_events_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF prc_events FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
};
