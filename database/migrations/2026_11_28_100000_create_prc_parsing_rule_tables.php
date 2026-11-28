<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE prc_parsing_rule_sets (
            id bigserial PRIMARY KEY,
            uuid uuid UNIQUE NOT NULL,
            platform_id bigint NOT NULL REFERENCES prc_platforms(id) ON DELETE NO ACTION,
            stage varchar(32) NOT NULL CHECK (stage IN ('listing', 'detail', 'status_refresh')),
            registry_code varchar(64) NULL,
            scope_key varchar(64) NOT NULL DEFAULT 'default',
            active_version_id bigint NULL,
            current_version_number integer NOT NULL DEFAULT 0 CHECK (current_version_number >= 0),
            auto_heal_mode varchar(32) NOT NULL DEFAULT 'propose' CHECK (auto_heal_mode IN ('disabled', 'propose', 'auto_promote_fallback')),
            description text NULL,
            is_enabled boolean NOT NULL DEFAULT true,
            created_at timestamptz(6) NOT NULL,
            updated_at timestamptz(6) NOT NULL
        )");

        DB::statement("CREATE UNIQUE INDEX prc_parsing_rule_sets_scope_unique ON prc_parsing_rule_sets (platform_id, stage, COALESCE(registry_code, ''), scope_key)");
        DB::statement('CREATE INDEX prc_parsing_rule_sets_active_lookup_idx ON prc_parsing_rule_sets (platform_id, stage, is_enabled)');

        DB::statement("CREATE TABLE prc_parsing_rule_versions (
            id bigserial PRIMARY KEY,
            uuid uuid UNIQUE NOT NULL,
            rule_set_id bigint NOT NULL REFERENCES prc_parsing_rule_sets(id) ON DELETE NO ACTION,
            version integer NOT NULL CHECK (version >= 1),
            schema_id varchar(128) NOT NULL,
            schema_version smallint NOT NULL DEFAULT 1 CHECK (schema_version >= 1),
            status varchar(32) NOT NULL CHECK (status IN ('draft', 'validated', 'active', 'superseded', 'rejected')),
            rules_json jsonb NOT NULL CHECK (jsonb_typeof(rules_json) = 'object'),
            rules_hash bytea NOT NULL CHECK (octet_length(rules_hash) = 32),
            source varchar(32) NOT NULL DEFAULT 'manual' CHECK (source IN ('seed', 'manual', 'import', 'auto_heal', 'rollback')),
            change_summary text NOT NULL,
            validation_report jsonb NULL,
            parent_version_id bigint NULL REFERENCES prc_parsing_rule_versions(id) ON DELETE NO ACTION,
            created_by bigint NULL REFERENCES users(id) ON DELETE NO ACTION,
            activated_by bigint NULL REFERENCES users(id) ON DELETE NO ACTION,
            activated_at timestamptz(6) NULL,
            deactivated_at timestamptz(6) NULL,
            created_at timestamptz(6) NOT NULL,
            UNIQUE (rule_set_id, version),
            UNIQUE (rule_set_id, id),
            CONSTRAINT prc_parsing_rule_versions_lifecycle_check CHECK (
                (status = 'active' AND activated_at IS NOT NULL AND deactivated_at IS NULL)
                OR (status = 'superseded' AND activated_at IS NOT NULL AND deactivated_at IS NOT NULL AND deactivated_at >= activated_at)
                OR (status IN ('draft', 'validated', 'rejected') AND activated_at IS NULL AND deactivated_at IS NULL)
            )
        )");

        DB::statement("CREATE UNIQUE INDEX prc_parsing_rule_versions_one_active_idx ON prc_parsing_rule_versions (rule_set_id) WHERE status = 'active'");
        DB::statement('CREATE INDEX prc_parsing_rule_versions_status_idx ON prc_parsing_rule_versions (rule_set_id, status)');
        DB::statement('CREATE INDEX prc_parsing_rule_versions_created_at_idx ON prc_parsing_rule_versions (rule_set_id, created_at DESC)');

        DB::statement('ALTER TABLE prc_parsing_rule_sets ADD CONSTRAINT prc_parsing_rule_sets_active_version_fk FOREIGN KEY (id, active_version_id) REFERENCES prc_parsing_rule_versions (rule_set_id, id) ON DELETE NO ACTION');

        DB::statement("CREATE TABLE prc_parsing_rule_events (
            id bigserial PRIMARY KEY,
            rule_set_id bigint NOT NULL REFERENCES prc_parsing_rule_sets(id) ON DELETE NO ACTION,
            rule_version_id bigint NULL REFERENCES prc_parsing_rule_versions(id) ON DELETE NO ACTION,
            event_type varchar(32) NOT NULL CHECK (event_type IN ('created', 'validated', 'validation_failed', 'activated', 'rolled_back', 'archived', 'drift_detected', 'candidate_proposed', 'candidate_verified')),
            actor_type varchar(32) NOT NULL CHECK (actor_type IN ('user', 'system', 'cli', 'seeder', 'auto_heal')),
            actor_id bigint NULL REFERENCES users(id) ON DELETE NO ACTION,
            reason text NOT NULL,
            metadata jsonb NULL,
            occurred_at timestamptz(6) NOT NULL
        )");

        DB::statement('CREATE INDEX prc_parsing_rule_events_occurred_at_idx ON prc_parsing_rule_events (rule_set_id, occurred_at DESC)');
        DB::statement('CREATE INDEX prc_parsing_rule_events_type_occurred_at_idx ON prc_parsing_rule_events (rule_set_id, event_type, occurred_at DESC)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prc_parsing_rule_versions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'parsing rule versions are immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('superseded', 'rejected') THEN
                    RAISE EXCEPTION 'terminal parsing rule version cannot be modified' USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.uuid IS DISTINCT FROM OLD.uuid
                    OR NEW.rule_set_id IS DISTINCT FROM OLD.rule_set_id
                    OR NEW.version IS DISTINCT FROM OLD.version
                    OR NEW.schema_id IS DISTINCT FROM OLD.schema_id
                    OR NEW.schema_version IS DISTINCT FROM OLD.schema_version
                    OR NEW.rules_json IS DISTINCT FROM OLD.rules_json
                    OR NEW.rules_hash IS DISTINCT FROM OLD.rules_hash
                    OR NEW.source IS DISTINCT FROM OLD.source
                    OR NEW.change_summary IS DISTINCT FROM OLD.change_summary
                    OR NEW.parent_version_id IS DISTINCT FROM OLD.parent_version_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'immutable fields of parsing rule version cannot be modified' USING ERRCODE = '23514';
                END IF;

                IF OLD.status NOT IN ('draft', 'validated') AND NEW.validation_report IS DISTINCT FROM OLD.validation_report THEN
                    RAISE EXCEPTION 'validation_report cannot be modified outside draft/validated status' USING ERRCODE = '23514';
                END IF;

                IF NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('draft', 'validated', 'active', 'rejected'))
                    OR (OLD.status = 'validated' AND NEW.status IN ('validated', 'active', 'rejected'))
                    OR (OLD.status = 'active' AND NEW.status IN ('active', 'superseded'))
                ) THEN
                    RAISE EXCEPTION 'invalid parsing rule version status transition from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END $$;

            CREATE TRIGGER prc_parsing_rule_versions_guard_trg
                BEFORE UPDATE OR DELETE ON prc_parsing_rule_versions
                FOR EACH ROW EXECUTE FUNCTION prc_parsing_rule_versions_guard();

            CREATE OR REPLACE FUNCTION prc_parsing_rule_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'parsing rule events are append-only' USING ERRCODE = '23514';
            END $$;

            CREATE TRIGGER prc_parsing_rule_events_immutable_trg
                BEFORE UPDATE OR DELETE ON prc_parsing_rule_events
                FOR EACH ROW EXECUTE FUNCTION prc_parsing_rule_events_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS prc_parsing_rule_events_immutable_trg ON prc_parsing_rule_events');
        DB::statement('DROP FUNCTION IF EXISTS prc_parsing_rule_events_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS prc_parsing_rule_versions_guard_trg ON prc_parsing_rule_versions');
        DB::statement('DROP FUNCTION IF EXISTS prc_parsing_rule_versions_guard()');
        DB::statement('ALTER TABLE prc_parsing_rule_sets DROP CONSTRAINT IF EXISTS prc_parsing_rule_sets_active_version_fk');
        DB::statement('DROP TABLE IF EXISTS prc_parsing_rule_events');
        DB::statement('DROP TABLE IF EXISTS prc_parsing_rule_versions');
        DB::statement('DROP TABLE IF EXISTS prc_parsing_rule_sets');
    }
};
