<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE prc_detail_snapshots (
              id uuid PRIMARY KEY,
              procurement_id bigint NOT NULL REFERENCES prc_procurements(id),
              input_revision bigint NOT NULL CHECK(input_revision>=1),
              part_key text NOT NULL,
              completeness text NOT NULL,
              result_ref jsonb NOT NULL,
              result_ref_schema_version smallint NOT NULL,
              source_version text NULL,
              created_at timestamptz NOT NULL,
              UNIQUE(procurement_id,input_revision,part_key),
              CHECK (result_ref_schema_version = 1),
              CHECK (completeness IN ('complete','complete_empty','partial','unavailable','not_applicable')),
              CHECK (char_length(part_key) BETWEEN 1 AND 160),
              CHECK (jsonb_typeof(result_ref) = 'object')
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE prc_status_refreshes (
              id uuid PRIMARY KEY,
              procurement_id bigint NOT NULL REFERENCES prc_procurements(id),
              intent_id uuid NOT NULL REFERENCES prc_operation_intents(id),
              cycle_seq bigint NOT NULL CHECK(cycle_seq>=1),
              next_due_at timestamptz NOT NULL,
              source_version text NULL,
              observed_at timestamptz NULL,
              UNIQUE(procurement_id,cycle_seq),
              UNIQUE(intent_id)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE prc_status_refreshes');
        DB::statement('DROP TABLE prc_detail_snapshots');
    }
};
