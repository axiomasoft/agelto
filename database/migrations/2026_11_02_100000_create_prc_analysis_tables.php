<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Canonical immutable analysis and accepted result provenance. */
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
            CONSTRAINT prc_analysis_runs_coverage_pair CHECK ((declared_coverage IS NULL) = (declared_coverage_schema_version IS NULL)),
            CONSTRAINT prc_analysis_runs_coverage_schema CHECK (declared_coverage_schema_version IS NULL OR declared_coverage_schema_version IN (1,2)),
            CONSTRAINT prc_analysis_runs_coverage_source CHECK (declared_coverage_schema_version IS DISTINCT FROM 2 OR
                (jsonb_typeof(declared_coverage->\'source\') IS NOT DISTINCT FROM \'object\' AND
                 (jsonb_typeof(declared_coverage->\'estimates\') IS NOT DISTINCT FROM \'null\' OR
                  jsonb_typeof(declared_coverage->\'estimates\') IS NOT DISTINCT FROM \'array\'))),
            CONSTRAINT prc_analysis_runs_publication_check CHECK(publication_status IN (\'unpublished\',\'published\')),
            CONSTRAINT prc_analysis_runs_publication_pair CHECK((publication_status=\'published\')=(publication_digest IS NOT NULL)),
            screening_result jsonb NULL,
            screening_result_schema_version smallint NULL,
            screening_disposition text NULL,
            declared_coverage jsonb NULL,
            declared_coverage_schema_version smallint NULL,
            publication_digest bytea NULL CHECK(publication_digest IS NULL OR octet_length(publication_digest)=32),
            CONSTRAINT prc_analysis_runs_screening_disposition_check CHECK(screening_disposition IN (\'extract\',\'retain\',\'review\',\'ignore\')),
            CONSTRAINT prc_analysis_runs_screening_schema_pair CHECK ((screening_result IS NULL) = (screening_result_schema_version IS NULL)),
            CONSTRAINT prc_analysis_runs_screening_disposition_pair CHECK ((screening_result IS NULL) = (screening_disposition IS NULL)),
            CONSTRAINT prc_analysis_runs_screening_schema_check CHECK (screening_result_schema_version IS NULL OR screening_result_schema_version=1),
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
        DB::statement('ALTER TABLE prc_ai_analyses ADD CONSTRAINT prc_ai_analyses_analysis_run_fk FOREIGN KEY(analysis_run_id) REFERENCES prc_analysis_runs(id)');
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
        DB::statement('ALTER TABLE prc_document_versions ADD COLUMN published_analysis_run_id uuid NULL,
            ADD CONSTRAINT prc_document_versions_published_analysis_identity_fk FOREIGN KEY(published_analysis_run_id,id) REFERENCES prc_analysis_runs(id,document_version_id)');
        DB::statement('ALTER TABLE prc_estimates ALTER COLUMN analysis_id DROP NOT NULL,
            ADD COLUMN analysis_run_id uuid NULL REFERENCES prc_analysis_runs(id),
            ADD CONSTRAINT prc_estimates_analysis_owner_check CHECK ((analysis_id IS NULL) <> (analysis_run_id IS NULL)),
            ADD CONSTRAINT prc_estimates_analysis_run_ordinal_unique UNIQUE(analysis_run_id,ordinal)');
        DB::statement('ALTER TABLE prc_estimates ALTER COLUMN platform_id DROP NOT NULL');
        foreach (['prc_estimates', 'prc_estimate_items', 'prc_estimate_resources', 'prc_estimate_sections', 'prc_estimate_totals'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN analysis_provenance jsonb NULL, ADD COLUMN analysis_provenance_schema_version smallint NULL,
                ADD CONSTRAINT {$table}_provenance_pair CHECK((analysis_provenance IS NULL)=(analysis_provenance_schema_version IS NULL)),
                ADD CONSTRAINT {$table}_provenance_schema CHECK(analysis_provenance_schema_version IS NULL OR analysis_provenance_schema_version=1)");
        }
        // The canonical decimal-string contract must never be rounded by the read model.
        foreach (['prc_estimates' => ['total_amount', 'labor_hours_workers', 'labor_hours_machinists'],
            'prc_estimate_items' => ['unit_multiplier', 'quantity', 'unit_price_base', 'unit_price_curr', 'total_price_base', 'total_price_curr', 'index_value'],
            'prc_estimate_resources' => ['unit_multiplier', 'quantity', 'price_base', 'price_curr', 'index_value'],
            'prc_estimate_totals' => ['amount', 'rate']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric");
            }
        }
        DB::statement('ALTER TABLE prc_estimate_totals ADD COLUMN source_node_id text NULL,
            ADD COLUMN source_parent_node_id text NULL, ADD CONSTRAINT prc_estimate_totals_source_node_unique UNIQUE(estimate_id,source_node_id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prc_analysis_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    RAISE EXCEPTION 'Analysis provenance is immutable' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='prc_analysis_runs' THEN
                    IF (to_jsonb(OLD)-ARRAY['generation','publication_status','publication_digest','declared_coverage','declared_coverage_schema_version','screening_result','screening_result_schema_version','screening_disposition']) IS DISTINCT FROM (to_jsonb(NEW)-ARRAY['generation','publication_status','publication_digest','declared_coverage','declared_coverage_schema_version','screening_result','screening_result_schema_version','screening_disposition']) THEN
                        RAISE EXCEPTION 'Analysis recipe is immutable' USING ERRCODE='23514';
                    END IF;
                    IF OLD.declared_coverage IS NOT NULL AND (OLD.declared_coverage,OLD.declared_coverage_schema_version) IS DISTINCT FROM (NEW.declared_coverage,NEW.declared_coverage_schema_version)
                        AND NOT (OLD.declared_coverage_schema_version=2 AND NEW.declared_coverage_schema_version=2
                            AND OLD.declared_coverage->'source' IS NOT DISTINCT FROM NEW.declared_coverage->'source'
                            AND OLD.declared_coverage->'estimates' IS NOT DISTINCT FROM 'null'::jsonb
                            AND jsonb_typeof(NEW.declared_coverage->'estimates') IS NOT DISTINCT FROM 'array') THEN
                        RAISE EXCEPTION 'Declared source coverage is immutable' USING ERRCODE='23514';
                    END IF;
                    IF OLD.publication_digest IS NOT NULL AND (OLD.publication_status,OLD.publication_digest) IS DISTINCT FROM (NEW.publication_status,NEW.publication_digest) THEN
                        RAISE EXCEPTION 'Accepted publication is immutable' USING ERRCODE='23514';
                    END IF;
                    IF OLD.screening_result IS NOT NULL AND
                        (OLD.screening_result,OLD.screening_result_schema_version,OLD.screening_disposition) IS DISTINCT FROM
                        (NEW.screening_result,NEW.screening_result_schema_version,NEW.screening_disposition) THEN
                        RAISE EXCEPTION 'Accepted screening is immutable' USING ERRCODE='23514';
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
        DB::statement('ALTER TABLE prc_ai_analyses DROP CONSTRAINT prc_ai_analyses_analysis_run_fk');
        DB::statement('ALTER TABLE prc_document_versions DROP CONSTRAINT prc_document_versions_published_analysis_identity_fk, DROP COLUMN published_analysis_run_id');
        DB::statement('ALTER TABLE prc_estimates DROP CONSTRAINT prc_estimates_analysis_owner_check, DROP CONSTRAINT prc_estimates_analysis_run_ordinal_unique, DROP COLUMN analysis_run_id');
        foreach (['prc_estimates', 'prc_estimate_items', 'prc_estimate_resources', 'prc_estimate_sections', 'prc_estimate_totals'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN analysis_provenance, DROP COLUMN analysis_provenance_schema_version");
        }
        DB::statement('ALTER TABLE prc_estimate_totals DROP COLUMN source_node_id, DROP COLUMN source_parent_node_id');
        DB::statement('ALTER TABLE prc_document_versions DROP CONSTRAINT prc_document_versions_current_analysis_identity_fk, DROP COLUMN current_analysis_run_id, DROP COLUMN analysis_generation');
        DB::statement('ALTER TABLE prc_process_work DROP CONSTRAINT prc_process_work_analysis_window_fk, DROP CONSTRAINT prc_process_work_analysis_run_fk');
        DB::statement('DROP TABLE prc_analysis_chunks, prc_analysis_windows, prc_analysis_runs');
        DB::statement('ALTER TABLE prc_document_representations DROP CONSTRAINT prc_document_representations_analysis_unique');
        DB::statement('DROP FUNCTION prc_analysis_immutable_guard()');
    }
};
