<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P1.6: durable факты фактических внешних запросов attempt и их observations
 * (execution#s04). Writer/readers и FK target/route на `prc_source_targets`/`prc_network_routes`
 * добавляет P2.1–P2.6 в этот же файл; до этого колонки без FK, а не placeholder таблицы.
 * `prc_observation_applications` — dedup записи reducer в одной tx с projection.
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
        foreach (['prc_observation_applications', 'prc_observations', 'prc_request_attempts'] as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /** @return list<string> */
    private function statements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE prc_request_attempts (
              request_id uuid PRIMARY KEY,
              attempt_id uuid NOT NULL REFERENCES prc_process_attempts(id),
              request_seq bigint NOT NULL CHECK (request_seq >= 1),
              target_id uuid NULL,
              route_id uuid NULL,
              started_at timestamptz NOT NULL,
              sent_at timestamptz NULL,
              finished_at timestamptz NULL,
              phase text NOT NULL,
              outcome text NULL,
              bytes bigint NOT NULL DEFAULT 0 CHECK (bytes >= 0),
              status smallint NULL,
              duration_ms bigint NULL CHECK (duration_ms >= 0),
              epochs jsonb NOT NULL,
              epochs_schema_version smallint NOT NULL,
              artifacts jsonb NULL,
              artifacts_schema_version smallint NULL,
              CONSTRAINT prc_request_attempts_attempt_seq_unique UNIQUE (attempt_id, request_seq),
              CONSTRAINT prc_request_attempts_artifacts_schema_check CHECK ((artifacts IS NULL) = (artifacts_schema_version IS NULL)),
              CHECK (epochs_schema_version > 0),
              CHECK (artifacts_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_observations (
              observation_id uuid PRIMARY KEY,
              request_id uuid NULL REFERENCES prc_request_attempts(request_id),
              attempt_id uuid NULL REFERENCES prc_process_attempts(id),
              kind text NOT NULL,
              reason_code text NOT NULL,
              input_hash bytea NOT NULL CHECK (octet_length(input_hash)=32),
              generation bigint NOT NULL CHECK (generation >= 0),
              epochs jsonb NOT NULL,
              epochs_schema_version smallint NOT NULL,
              stale boolean NOT NULL DEFAULT false,
              details jsonb NULL,
              details_schema_version smallint NULL,
              occurred_at timestamptz NOT NULL,
              CONSTRAINT prc_observations_details_schema_check CHECK ((details IS NULL) = (details_schema_version IS NULL)),
              CHECK (epochs_schema_version > 0),
              CHECK (details_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_observation_applications (
              observation_id uuid NOT NULL REFERENCES prc_observations(observation_id),
              projection_key text NOT NULL,
              reducer_version bigint NOT NULL CHECK (reducer_version >= 1),
              applied_at timestamptz NOT NULL,
              PRIMARY KEY (observation_id, projection_key)
            )
            SQL,
        ];
    }
};
