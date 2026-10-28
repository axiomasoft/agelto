<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P1.15: durable состояние projectors (observability#s08). State — gate rebuild/retention и
 * активная generation totals; receipts — глобальный dedup `(projector_key, event_id)` независимо
 * от generation; totals — только counter-серии, current gauges читаются из authoritative rows.
 * Receipt.event_id RESTRICT: история удаляется лишь retention writer-ом под state locks.
 * Все CHECK именованы явно, чтобы имена catalog не зависели от автогенерации PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->statements() as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        foreach (['prc_process_projection_receipts', 'prc_process_metric_totals', 'prc_process_projection_states'] as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /** @return list<string> */
    private function statements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE prc_process_projection_states (
              projector_key text PRIMARY KEY,
              mode text NOT NULL CHECK (mode IN ('active','maintenance')),
              active_generation bigint NOT NULL CHECK (active_generation > 0),
              catalog_hash bytea NOT NULL CHECK (octet_length(catalog_hash)=32),
              history_complete boolean NOT NULL DEFAULT true,
              history_pruned_at timestamptz NULL,
              maintenance_token uuid NULL,
              maintenance_started_at timestamptz NULL,
              rebuilt_at timestamptz NULL,
              CONSTRAINT prc_process_projection_states_maintenance_token_check CHECK ((mode='maintenance') = (maintenance_token IS NOT NULL)),
              CONSTRAINT prc_process_projection_states_maintenance_started_check CHECK ((mode='maintenance') = (maintenance_started_at IS NOT NULL)),
              CONSTRAINT prc_process_projection_states_history_check CHECK (history_complete = (history_pruned_at IS NULL))
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_metric_totals (
              projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key),
              generation bigint NOT NULL CHECK (generation > 0),
              metric_key text NOT NULL,
              dimension_key text NOT NULL,
              value bigint NOT NULL CHECK (value >= 0),
              updated_at timestamptz NOT NULL,
              PRIMARY KEY (projector_key, generation, metric_key, dimension_key)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_projection_receipts (
              projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key),
              event_id uuid NOT NULL REFERENCES prc_process_transitions(event_id) ON DELETE RESTRICT,
              applied_at timestamptz NOT NULL,
              PRIMARY KEY (projector_key, event_id)
            )
            SQL,
            // Retention и rebuild ищут receipts события и по kind; PK ведёт только по projector_key.
            'CREATE INDEX prc_process_projection_receipts_event_idx ON prc_process_projection_receipts (event_id)',
        ];
    }
};
