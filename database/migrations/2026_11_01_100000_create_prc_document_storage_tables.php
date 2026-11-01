<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentBlob;
use App\Models\Procurement\Document\StorageDomain;
use App\Models\Procurement\Document\StorageReservation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Real storage ownership, staged until the document runtime cutover. */
return new class extends Migration
{
    public function up(): void
    {
        $domains = (new StorageDomain)->getTable();
        $reservations = (new StorageReservation)->getTable();
        $blobs = (new DocumentBlob)->getTable();
        DB::statement("CREATE TABLE {$domains} (
            id uuid PRIMARY KEY,
            domain_key text NOT NULL UNIQUE,
            capacity_bytes bigint NOT NULL CHECK(capacity_bytes>0),
            high_watermark_bytes bigint NOT NULL CHECK(high_watermark_bytes>0 AND high_watermark_bytes<=capacity_bytes),
            accounted_durable_bytes bigint NOT NULL CHECK(accounted_durable_bytes>=0 AND accounted_durable_bytes<=capacity_bytes),
            policy_version bigint NOT NULL CHECK(policy_version>=1)
        )");
        DB::statement("CREATE TABLE {$reservations} (
            id uuid PRIMARY KEY,
            domain_id uuid NOT NULL REFERENCES {$domains}(id),
            work_id uuid NULL REFERENCES prc_process_work(id),
            attempt_id uuid NULL REFERENCES prc_process_attempts(id),
            operation_id uuid NULL REFERENCES prc_outbound_operations(id),
            upload_intent_id uuid NOT NULL UNIQUE,
            owner_token uuid NOT NULL,
            generation bigint NOT NULL CHECK(generation>=1),
            byte_upper_bound bigint NOT NULL CHECK(byte_upper_bound>0),
            phase text NOT NULL CHECK(phase IN ('download','prepare')),
            status text NOT NULL CHECK(status IN ('reserved','writing','staged','cleaning','cleaned','transferred')),
            cleanup_verified_at timestamptz NULL,
            disk text NOT NULL CHECK(length(disk)>0),
            object_key text NOT NULL CHECK(length(object_key)>0),
            created_at timestamptz NOT NULL,
            CONSTRAINT prc_storage_reservations_owner_check CHECK((work_id IS NULL)=(attempt_id IS NULL) AND NOT(work_id IS NOT NULL AND operation_id IS NOT NULL)),
            CONSTRAINT prc_storage_reservations_attempt_work_fk FOREIGN KEY(attempt_id,work_id) REFERENCES prc_process_attempts(id,work_id),
            CONSTRAINT prc_storage_reservations_cleanup_check CHECK((status='cleaned')=(cleanup_verified_at IS NOT NULL)),
            UNIQUE(disk,object_key)
        )");
        DB::statement("CREATE INDEX prc_storage_reservations_active_idx ON {$reservations}(domain_id) WHERE status IN ('reserved','writing','staged','cleaning')");
        DB::statement("CREATE TABLE {$blobs} (
            id uuid PRIMARY KEY,
            storage_domain_id uuid NOT NULL REFERENCES {$domains}(id),
            content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32),
            disk text NOT NULL CHECK(length(disk)>0),
            object_key text NOT NULL CHECK(length(object_key)>0),
            size_bytes bigint NOT NULL CHECK(size_bytes>=0),
            verified_at timestamptz NOT NULL,
            deletion_generation bigint NOT NULL DEFAULT 0 CHECK(deletion_generation>=0),
            deletion_marked_at timestamptz NULL,
            UNIQUE(storage_domain_id,content_hash),
            UNIQUE(disk,object_key)
        )");
        DB::statement('ALTER TABLE prc_document_versions ADD CONSTRAINT prc_document_versions_blob_fk FOREIGN KEY(blob_id) REFERENCES prc_document_blobs(id)');
        DB::statement('ALTER TABLE prc_documents ADD COLUMN current_version_id bigint NULL,
            ADD CONSTRAINT prc_documents_current_version_fk FOREIGN KEY(current_version_id,id) REFERENCES prc_document_versions(id,document_id)');
        DB::statement('ALTER TABLE prc_document_representations ADD CONSTRAINT prc_document_representations_blob_fk FOREIGN KEY(blob_id) REFERENCES prc_document_blobs(id)');
        DB::statement("ALTER TABLE prc_document_versions ADD CONSTRAINT prc_document_versions_canonical_tuple_check CHECK (
            (revision_no IS NULL AND blob_id IS NULL AND first_seen_at IS NULL AND normalized_entry_path IS NULL AND child_ordinal IS NULL AND etag IS NULL AND last_modified IS NULL)
            OR (revision_no IS NOT NULL AND revision_no>=1 AND blob_id IS NOT NULL AND first_seen_at IS NOT NULL AND disk IS NOT NULL AND length(disk)>0
                AND content_hash ~ '^[0-9a-f]{64}$' AND size_bytes>=0
                AND ((parent_version_id IS NULL AND normalized_entry_path IS NULL AND child_ordinal IS NULL)
                    OR (parent_version_id IS NOT NULL AND normalized_entry_path IS NOT NULL AND length(normalized_entry_path)>0 AND child_ordinal IS NOT NULL AND child_ordinal>=0))))");
        DB::statement("ALTER TABLE prc_document_representations ADD CONSTRAINT prc_document_representations_canonical_tuple_check CHECK (
            (blob_id IS NULL AND preparation_recipe_hash IS NULL AND content_hash IS NULL AND range_key IS NULL AND tool_version IS NULL AND coverage_manifest IS NULL AND coverage_manifest_schema_version IS NULL)
            OR (blob_id IS NOT NULL AND preparation_recipe_hash IS NOT NULL AND octet_length(preparation_recipe_hash)=32 AND content_hash IS NOT NULL AND octet_length(content_hash)=32
                AND range_key IS NOT NULL AND length(range_key)>0 AND tool_version IS NOT NULL AND length(tool_version)>0 AND disk IS NOT NULL AND length(disk)>0
                AND size_bytes IS NOT NULL AND size_bytes>=0 AND coverage_manifest IS NOT NULL AND jsonb_typeof(coverage_manifest)='object' AND coverage_manifest_schema_version IS NOT NULL AND coverage_manifest_schema_version=1))");
        // DB guard is shared by every writer: direct SQL cannot acquire a reference past GC mark.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prc_document_blob_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE b prc_document_blobs%ROWTYPE;
            BEGIN
                IF NEW.blob_id IS NULL THEN
                    IF TG_TABLE_NAME='prc_document_versions' THEN
                        IF EXISTS(SELECT 1 FROM prc_documents WHERE id=NEW.document_id AND current_version_id IS NOT NULL) THEN
                            RAISE EXCEPTION 'Legacy writer cannot append to canonical document' USING ERRCODE='23514';
                        END IF;
                    ELSE
                        IF EXISTS(SELECT 1 FROM prc_document_versions WHERE id=NEW.document_version_id AND blob_id IS NOT NULL) THEN
                            RAISE EXCEPTION 'Canonical version requires canonical representation' USING ERRCODE='23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;
                SELECT * INTO b FROM prc_document_blobs WHERE id=NEW.blob_id FOR KEY SHARE;
                IF NOT FOUND OR b.deletion_marked_at IS NOT NULL OR b.disk<>NEW.disk OR b.object_key<>NEW.storage_path OR b.size_bytes<>NEW.size_bytes THEN
                    RAISE EXCEPTION 'Blob reference is unavailable or inconsistent' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='prc_document_versions' THEN
                    IF encode(b.content_hash,'hex')<>NEW.content_hash THEN RAISE EXCEPTION 'Blob hash mismatch' USING ERRCODE='23514'; END IF;
                ELSE
                    IF b.content_hash<>NEW.content_hash THEN RAISE EXCEPTION 'Blob hash mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER prc_versions_blob_gate BEFORE INSERT OR UPDATE ON prc_document_versions FOR EACH ROW EXECUTE FUNCTION prc_document_blob_reference_guard();
            CREATE TRIGGER prc_representations_blob_gate BEFORE INSERT OR UPDATE ON prc_document_representations FOR EACH ROW EXECUTE FUNCTION prc_document_blob_reference_guard();

            CREATE OR REPLACE FUNCTION prc_document_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.blob_id IS NOT NULL AND (TG_OP='DELETE' OR
                    (to_jsonb(OLD)-ARRAY['updated_at','analysis_generation','current_analysis_run_id']) IS DISTINCT FROM
                    (to_jsonb(NEW)-ARRAY['updated_at','analysis_generation','current_analysis_run_id'])) THEN
                    RAISE EXCEPTION 'Canonical document artifact is immutable' USING ERRCODE='23514';
                END IF;
                RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
            END $$;
            CREATE TRIGGER prc_versions_immutable BEFORE UPDATE OR DELETE ON prc_document_versions FOR EACH ROW EXECUTE FUNCTION prc_document_immutable_guard();
            CREATE TRIGGER prc_representations_immutable BEFORE UPDATE OR DELETE ON prc_document_representations FOR EACH ROW EXECUTE FUNCTION prc_document_immutable_guard();
            SQL);

    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER prc_versions_blob_gate ON prc_document_versions');
        DB::statement('DROP TRIGGER prc_representations_blob_gate ON prc_document_representations');
        DB::statement('DROP TRIGGER prc_versions_immutable ON prc_document_versions');
        DB::statement('DROP TRIGGER prc_representations_immutable ON prc_document_representations');
        DB::statement('DROP FUNCTION prc_document_blob_reference_guard()');
        DB::statement('DROP FUNCTION prc_document_immutable_guard()');
        DB::statement('ALTER TABLE prc_documents DROP COLUMN current_version_id');
        DB::statement('ALTER TABLE prc_document_versions DROP CONSTRAINT prc_document_versions_blob_fk, DROP CONSTRAINT prc_document_versions_canonical_tuple_check');
        DB::statement('ALTER TABLE prc_document_representations DROP CONSTRAINT prc_document_representations_blob_fk, DROP CONSTRAINT prc_document_representations_canonical_tuple_check');
        DB::statement('DROP TABLE '.(new DocumentBlob)->getTable());
        DB::statement('DROP TABLE '.(new StorageReservation)->getTable());
        DB::statement('DROP TABLE '.(new StorageDomain)->getTable());
    }
};
