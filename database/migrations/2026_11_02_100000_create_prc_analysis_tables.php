<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** P3.8 additive carriers. Canonical names; legacy model bindings remain until P3.13. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE prc_analysis_runs (
            id uuid PRIMARY KEY,
            document_version_id bigint NOT NULL REFERENCES prc_document_versions(id),
            representation_id bigint NOT NULL REFERENCES prc_document_representations(id),
            representation_hash bytea NOT NULL CHECK(octet_length(representation_hash)=32),
            recipe_json jsonb NOT NULL,
            recipe_schema_version smallint NOT NULL,
            recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32),
            generation bigint NOT NULL CHECK(generation>=1),
            expected_document_head_generation bigint NOT NULL CHECK(expected_document_head_generation>=1),
            expected_analysis_generation bigint NOT NULL CHECK(expected_analysis_generation>=1),
            requested_reason text NOT NULL,
            supersedes_run_id uuid NULL REFERENCES prc_analysis_runs(id),
            publication_status text NOT NULL,
            CHECK (recipe_schema_version > 0)
        )');
        DB::statement('CREATE TABLE prc_analysis_windows (
            id uuid PRIMARY KEY,
            run_id uuid NOT NULL REFERENCES prc_analysis_runs(id),
            window_key text NOT NULL,
            input_hash bytea NOT NULL CHECK(octet_length(input_hash)=32),
            input_range jsonb NOT NULL,
            input_range_schema_version smallint NOT NULL,
            expected_coverage jsonb NOT NULL,
            expected_coverage_schema_version smallint NOT NULL,
            state text NOT NULL,
            checkpoint_version bigint NOT NULL CHECK(checkpoint_version>=0),
            UNIQUE(run_id,window_key),
            CHECK (input_range_schema_version > 0),
            CHECK (expected_coverage_schema_version > 0)
        )');
        DB::statement('CREATE TABLE prc_analysis_chunks (
            id uuid PRIMARY KEY,
            run_id uuid NOT NULL REFERENCES prc_analysis_runs(id),
            window_id uuid NOT NULL REFERENCES prc_analysis_windows(id),
            window_key text NOT NULL,
            recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32),
            subrange_key bytea NOT NULL CHECK(octet_length(subrange_key)=32),
            output_ordinal bigint NOT NULL CHECK(output_ordinal>=0),
            output_ref jsonb NOT NULL,
            output_ref_schema_version smallint NOT NULL,
            output_hash bytea NOT NULL CHECK(octet_length(output_hash)=32),
            canonical_rows jsonb NOT NULL,
            canonical_rows_schema_version smallint NOT NULL,
            provenance jsonb NOT NULL,
            provenance_schema_version smallint NOT NULL,
            completed_at timestamptz NOT NULL,
            UNIQUE(run_id,window_key,recipe_hash,subrange_key,output_ordinal),
            CHECK (output_ref_schema_version > 0),
            CHECK (canonical_rows_schema_version > 0),
            CHECK (provenance_schema_version > 0)
        )');
        DB::statement('ALTER TABLE prc_analysis_runs ADD CONSTRAINT prc_analysis_runs_id_recipe_unique UNIQUE(id,recipe_hash)');
        DB::statement('ALTER TABLE prc_document_representations ADD CONSTRAINT prc_document_representations_analysis_unique UNIQUE(id,document_version_id,content_hash)');
        DB::statement('ALTER TABLE prc_analysis_runs ADD CONSTRAINT prc_analysis_runs_representation_identity_fk FOREIGN KEY(representation_id,document_version_id,representation_hash) REFERENCES prc_document_representations(id,document_version_id,content_hash),
            ADD CONSTRAINT prc_analysis_runs_version_identity_unique UNIQUE(id,document_version_id)');
        DB::statement('ALTER TABLE prc_analysis_windows ADD CONSTRAINT prc_analysis_windows_identity_unique UNIQUE(id,run_id,window_key)');
        DB::statement('ALTER TABLE prc_analysis_chunks ADD CONSTRAINT prc_analysis_chunks_window_identity_fk FOREIGN KEY(window_id,run_id,window_key) REFERENCES prc_analysis_windows(id,run_id,window_key),
            ADD CONSTRAINT prc_analysis_chunks_recipe_fk FOREIGN KEY(run_id,recipe_hash) REFERENCES prc_analysis_runs(id,recipe_hash)');
        DB::statement('ALTER TABLE prc_process_work ADD CONSTRAINT prc_process_work_analysis_window_fk FOREIGN KEY(analysis_window_id) REFERENCES prc_analysis_windows(id),
            ADD CONSTRAINT prc_process_work_analysis_run_fk FOREIGN KEY(analysis_run_id) REFERENCES prc_analysis_runs(id)');
        DB::statement('ALTER TABLE prc_document_versions ADD COLUMN analysis_generation bigint NOT NULL DEFAULT 1 CHECK(analysis_generation>=1),
            ADD COLUMN current_analysis_run_id uuid NULL REFERENCES prc_analysis_runs(id)');
        DB::statement('ALTER TABLE prc_document_versions ADD CONSTRAINT prc_document_versions_current_analysis_identity_fk FOREIGN KEY(current_analysis_run_id,id) REFERENCES prc_analysis_runs(id,document_version_id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prc_analysis_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    RAISE EXCEPTION 'Analysis provenance is immutable' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='prc_analysis_runs' THEN
                    IF (to_jsonb(OLD)-ARRAY['generation','publication_status']) IS DISTINCT FROM (to_jsonb(NEW)-ARRAY['generation','publication_status']) THEN
                        RAISE EXCEPTION 'Analysis recipe is immutable' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='prc_analysis_windows' THEN
                    IF (to_jsonb(OLD)-ARRAY['state','checkpoint_version']) IS DISTINCT FROM (to_jsonb(NEW)-ARRAY['state','checkpoint_version']) THEN
                        RAISE EXCEPTION 'Analysis input is immutable' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF OLD IS DISTINCT FROM NEW THEN
                        RAISE EXCEPTION 'Accepted chunk is immutable' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER prc_analysis_runs_immutable BEFORE UPDATE OR DELETE ON prc_analysis_runs FOR EACH ROW EXECUTE FUNCTION prc_analysis_immutable_guard();
            CREATE TRIGGER prc_analysis_windows_immutable BEFORE UPDATE OR DELETE ON prc_analysis_windows FOR EACH ROW EXECUTE FUNCTION prc_analysis_immutable_guard();
            CREATE TRIGGER prc_analysis_chunks_immutable BEFORE UPDATE OR DELETE ON prc_analysis_chunks FOR EACH ROW EXECUTE FUNCTION prc_analysis_immutable_guard();
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prc_document_versions DROP CONSTRAINT prc_document_versions_current_analysis_identity_fk, DROP COLUMN current_analysis_run_id, DROP COLUMN analysis_generation');
        DB::statement('ALTER TABLE prc_process_work DROP CONSTRAINT prc_process_work_analysis_window_fk, DROP CONSTRAINT prc_process_work_analysis_run_fk');
        DB::statement('DROP TABLE prc_analysis_chunks, prc_analysis_windows, prc_analysis_runs');
        DB::statement('ALTER TABLE prc_document_representations DROP CONSTRAINT prc_document_representations_analysis_unique');
        DB::statement('DROP FUNCTION prc_analysis_immutable_guard()');
    }
};
