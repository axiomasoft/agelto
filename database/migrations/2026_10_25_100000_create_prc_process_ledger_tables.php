<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL ledger исполнения. Порядок CREATE следует зависимостям FK:
 * control scopes и lanes → runs/incidents → handler authority и transitions →
 * crawl subjects/intents → work/attempts → admission groups/reservations →
 * run links/journal/outbox → daemon heartbeat.
 *
 * CHECK стадий покрывает listing, detail, status_refresh, download, prepare, screening,
 * extraction и publication. Каждая work имеет ровно один concrete subject своей стадии.
 * Lease/due решения используют `clock_timestamp()` и timestamptz; JSON/version pairs
 * и положительные schema_version защищены CHECK.
 *
 * Составной FK network demand `(attempt_id, work_id)` ссылается на
 * `prc_attempt_work_pair` и запрещает привязать demand к attempt чужой work.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->statements() as $statement) {
            DB::statement($statement);
        }
        DB::statement('ALTER TABLE prc_revisions ADD CONSTRAINT prc_revisions_process_run_fk FOREIGN KEY (process_run_id) REFERENCES prc_process_runs(id) ON DELETE NO ACTION');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prc_revisions DROP CONSTRAINT IF EXISTS prc_revisions_process_run_fk');
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    private const array TABLES = [
        'prc_control_scopes', 'prc_process_lanes', 'prc_process_runs', 'prc_process_incidents',
        'prc_process_handler_holds', 'prc_crawl_runs', 'prc_crawl_blocks', 'prc_operation_intents',
        'prc_process_work', 'prc_process_attempts', 'prc_admission_groups', 'prc_process_reservations',
        'prc_process_run_work', 'prc_process_transitions', 'prc_process_outbox', 'prc_process_daemon_heartbeats',
    ];

    /** @return list<string> */
    private function statements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE prc_control_scopes (
              id uuid PRIMARY KEY,
              scope_kind text NOT NULL CHECK (scope_kind IN ('system','acquisition','documents','platform','provider','cohort','stage')),
              scope_key text NOT NULL,
              parent_id uuid NULL REFERENCES prc_control_scopes(id),
              desired_mode text NOT NULL DEFAULT 'enabled' CHECK (desired_mode IN ('enabled','paused')),
              control_version bigint NOT NULL DEFAULT 1 CHECK (control_version >= 1),
              changed_by bigint NULL REFERENCES users(id),
              change_reason text NULL,
              changed_at timestamptz NULL,
              CONSTRAINT prc_control_scopes_scope_key_unique UNIQUE (scope_key),
              CONSTRAINT prc_control_scopes_root_check CHECK ((scope_kind = 'system') = (parent_id IS NULL))
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_lanes (
              id uuid PRIMARY KEY,
              lane_key text NOT NULL,
              parent_lane_id uuid NULL REFERENCES prc_process_lanes(id),
              max_outstanding integer NOT NULL CHECK (max_outstanding > 0),
              max_running integer NOT NULL CHECK (max_running > 0 AND max_running <= max_outstanding),
              low_watermark integer NOT NULL CHECK (low_watermark >= 0 AND low_watermark < max_outstanding),
              refill_max integer NOT NULL CHECK (refill_max > 0 AND refill_max <= max_outstanding),
              policy_version bigint NOT NULL DEFAULT 1 CHECK (policy_version >= 1),
              fairness_cursor jsonb NOT NULL DEFAULT '{}',
              fairness_cursor_schema_version smallint NOT NULL DEFAULT 1,
              CONSTRAINT prc_process_lanes_lane_key_unique UNIQUE (lane_key),
              CHECK (fairness_cursor_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_runs (
              id uuid PRIMARY KEY,
              kind text NOT NULL CHECK (kind IN ('profile','schedule','manual','recovery','eval')),
              trigger_key text NULL,
              profile_id bigint NULL REFERENCES prc_crawlers(id),
              configuration_snapshot jsonb NOT NULL,
              configuration_schema_version smallint NOT NULL,
              configuration_hash bytea NOT NULL CHECK (octet_length(configuration_hash)=32),
              requested_by bigint NULL REFERENCES users(id),
              requested_at timestamptz NOT NULL,
              started_at timestamptz NULL,
              finished_at timestamptz NULL,
              discovery_closed_at timestamptz NULL,
              cancel_requested_at timestamptz NULL,
              discovery_outcome text NOT NULL DEFAULT 'collecting' CHECK (discovery_outcome IN ('collecting','complete','incomplete')),
              discovery_reason_code text NULL,
              transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0),
              projection_version bigint NOT NULL DEFAULT 0 CHECK (projection_version >= 0),
              CONSTRAINT prc_process_runs_discovery_closed_check CHECK ((discovery_outcome = 'collecting') = (discovery_closed_at IS NULL)),
              CONSTRAINT prc_process_runs_discovery_reason_check CHECK ((discovery_outcome = 'incomplete') = (discovery_reason_code IS NOT NULL)),
              CONSTRAINT prc_process_runs_trigger_key_unique UNIQUE (trigger_key),
              CHECK (configuration_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_incidents (
              id uuid PRIMARY KEY,
              fingerprint bytea NOT NULL CHECK (octet_length(fingerprint)=32),
              scope_key text NOT NULL,
              health_epoch bigint NULL,
              kind text NOT NULL,
              state text NOT NULL CHECK (state IN ('open','acknowledged','resolved')),
              first_seen_at timestamptz NOT NULL,
              last_seen_at timestamptz NOT NULL,
              occurrences bigint NOT NULL DEFAULT 1 CHECK (occurrences >= 1),
              transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0),
              evidence jsonb NOT NULL,
              evidence_schema_version smallint NOT NULL,
              acknowledged_by bigint NULL REFERENCES users(id),
              resolved_at timestamptz NULL,
              CHECK (evidence_schema_version > 0)
            )
            SQL,
            "CREATE UNIQUE INDEX prc_process_incidents_active_fingerprint_unique ON prc_process_incidents (fingerprint) WHERE state <> 'resolved'",
            <<<'SQL'
            CREATE TABLE prc_process_handler_holds (
              id uuid PRIMARY KEY,
              stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')),
              handler_version text NOT NULL,
              state text NOT NULL DEFAULT 'healthy' CHECK (state IN ('healthy','held')),
              version bigint NOT NULL DEFAULT 1 CHECK (version >= 1),
              fault_signature bytea NULL CHECK (fault_signature IS NULL OR octet_length(fault_signature)=32),
              incident_id uuid NULL REFERENCES prc_process_incidents(id),
              held_at timestamptz NULL,
              CONSTRAINT prc_process_handler_holds_stage_version_unique UNIQUE (stage, handler_version),
              CONSTRAINT prc_process_handler_holds_held_check CHECK (state <> 'held' OR (fault_signature IS NOT NULL AND incident_id IS NOT NULL AND held_at IS NOT NULL))
            )
            SQL,
            // Immutable acquisition subject snapshot: без execution state/lease/attempt counter.
            <<<'SQL'
            CREATE TABLE prc_crawl_runs (
              id uuid PRIMARY KEY,
              process_run_id uuid NOT NULL REFERENCES prc_process_runs(id),
              listing_kind text NOT NULL CHECK (listing_kind IN ('fresh','archive','delta')),
              filter_payload jsonb NOT NULL,
              filter_schema_version smallint NOT NULL CHECK (filter_schema_version > 0),
              filter_hash bytea NOT NULL CHECK (octet_length(filter_hash)=32),
              access_policy_hash bytea NOT NULL CHECK (octet_length(access_policy_hash)=32),
              adapter_version text NOT NULL,
              CONSTRAINT prc_crawl_runs_process_run_id_unique UNIQUE (process_run_id)
            )
            SQL,
            // cursor/terminal proof — domain progress, пишутся только guarded checkpoint/finalize work.
            <<<'SQL'
            CREATE TABLE prc_crawl_blocks (
              id uuid PRIMARY KEY,
              crawl_run_id uuid NOT NULL REFERENCES prc_crawl_runs(id),
              parent_block_id uuid NULL REFERENCES prc_crawl_blocks(id),
              block_key text NOT NULL,
              partition_payload jsonb NOT NULL,
              partition_schema_version smallint NOT NULL CHECK (partition_schema_version > 0),
              filter_hash bytea NOT NULL CHECK (octet_length(filter_hash)=32),
              head_generation bigint NOT NULL DEFAULT 1 CHECK (head_generation >= 1),
              input_revision bigint NOT NULL DEFAULT 1 CHECK (input_revision >= 1),
              cursor jsonb NOT NULL DEFAULT '{}',
              cursor_schema_version smallint NOT NULL DEFAULT 1 CHECK (cursor_schema_version > 0),
              terminal_proof jsonb NULL,
              terminal_proof_schema_version smallint NULL,
              limitation_reason_code text NULL,
              CONSTRAINT prc_crawl_blocks_run_key_unique UNIQUE (crawl_run_id, block_key),
              CONSTRAINT prc_crawl_blocks_proof_schema_check CHECK ((terminal_proof IS NULL) = (terminal_proof_schema_version IS NULL)),
              CHECK (terminal_proof_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_operation_intents (
              id uuid PRIMARY KEY,
              intent_key bytea NOT NULL CHECK (octet_length(intent_key)=32),
              stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')),
              subject_kind text NOT NULL,
              subject_id text NOT NULL,
              semantic_policy_hash bytea NOT NULL CHECK (octet_length(semantic_policy_hash)=32),
              access_policy_hash bytea NOT NULL CHECK (octet_length(access_policy_hash)=32),
              cycle_seq bigint NOT NULL DEFAULT 1 CHECK (cycle_seq >= 1),
              expected_head_generation bigint NOT NULL CHECK (expected_head_generation >= 1),
              state text NOT NULL CHECK (state IN ('pending','active','terminal')),
              pending_reason text NULL CHECK (pending_reason IS NULL OR (state='pending' AND pending_reason='handler_not_activated')),
              requested_at timestamptz NOT NULL,
              next_due_at timestamptz NULL,
              CONSTRAINT prc_operation_intents_intent_key_unique UNIQUE (intent_key)
            )
            SQL,
            "CREATE UNIQUE INDEX prc_operation_intents_one_active_unique ON prc_operation_intents (stage, subject_kind, subject_id) WHERE state = 'active'",
            // Owner tuple: ровно один непустой concrete subject FK, соответствующий стадии.
            <<<'SQL'
            CREATE TABLE prc_process_work (
              id uuid PRIMARY KEY,
              stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')),
              work_key bytea NOT NULL CHECK (octet_length(work_key)=32),
              work_identity jsonb NOT NULL,
              work_identity_schema_version smallint NOT NULL,
              intent_id uuid NULL REFERENCES prc_operation_intents(id),
              input_revision bigint NOT NULL CHECK (input_revision >= 1),
              input_hash bytea NOT NULL CHECK (octet_length(input_hash)=32),
              input_payload jsonb NOT NULL,
              input_schema_version smallint NOT NULL,
              state text NOT NULL CHECK (state IN ('ready','admitted','running','deferred','blocked','succeeded','failed_item','cancelled','superseded')),
              reason_code text NULL,
              due_at timestamptz NULL,
              provider_recovery_due_at timestamptz NULL,
              priority smallint NOT NULL DEFAULT 100,
              platform_id bigint NULL REFERENCES prc_platforms(id),
              cohort_key text NOT NULL,
              origin_run_id uuid NULL REFERENCES prc_process_runs(id),
              crawl_block_id uuid NULL REFERENCES prc_crawl_blocks(id),
              procurement_id bigint NULL REFERENCES prc_procurements(id),
              document_id bigint NULL REFERENCES prc_documents(id),
              document_version_id bigint NULL REFERENCES prc_document_versions(id),
              analysis_window_id uuid NULL,
              analysis_run_id uuid NULL,
              generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0),
              owner_token uuid NULL,
              lease_until timestamptz NULL,
              heartbeat_at timestamptz NULL,
              dispatch_generation bigint NOT NULL DEFAULT 0 CHECK (dispatch_generation >= 0),
              dispatch_claim_until timestamptz NULL,
              checkpoint jsonb NULL,
              checkpoint_schema_version smallint NULL,
              checkpoint_seq bigint NOT NULL DEFAULT 0 CHECK (checkpoint_seq >= 0),
              execution_attempts bigint NOT NULL DEFAULT 0 CHECK (execution_attempts >= 0),
              transport_attempts bigint NOT NULL DEFAULT 0 CHECK (transport_attempts >= 0),
              semantic_reasks bigint NOT NULL DEFAULT 0 CHECK (semantic_reasks >= 0),
              item_failures integer NOT NULL DEFAULT 0 CHECK (item_failures BETWEEN 0 AND 3),
              infrastructure_interruptions bigint NOT NULL DEFAULT 0 CHECK (infrastructure_interruptions >= 0),
              transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT prc_process_work_work_key_unique UNIQUE (work_key),
              CONSTRAINT prc_process_work_checkpoint_schema_check CHECK ((checkpoint IS NULL) = (checkpoint_schema_version IS NULL)),
              CONSTRAINT prc_process_work_running_lease_check CHECK ((state = 'running' AND owner_token IS NOT NULL AND lease_until IS NOT NULL) OR (state <> 'running' AND owner_token IS NULL AND lease_until IS NULL)),
              CONSTRAINT prc_process_work_admitted_claim_check CHECK ((state = 'admitted') = (dispatch_claim_until IS NOT NULL)),
              CONSTRAINT prc_process_work_deferred_due_check CHECK (state <> 'deferred' OR due_at IS NOT NULL),
              CONSTRAINT prc_process_work_due_state_check CHECK (due_at IS NULL OR state = 'deferred'),
              CONSTRAINT prc_process_work_provider_recovery_due_check CHECK ((state = 'blocked' AND reason_code = 'provider_result_unknown') = (provider_recovery_due_at IS NOT NULL)),
              CONSTRAINT prc_process_work_reason_check CHECK (state NOT IN ('deferred','blocked','failed_item','cancelled','superseded') OR reason_code IS NOT NULL),
              CONSTRAINT prc_process_work_owner_check CHECK (
                   (stage = 'listing' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND crawl_block_id IS NOT NULL)
                OR (stage IN ('detail','status_refresh') AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND procurement_id IS NOT NULL)
                OR (stage IN ('prepare','screening') AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND document_version_id IS NOT NULL)
                OR (stage = 'extraction' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND analysis_window_id IS NOT NULL)
                OR (stage = 'publication' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND analysis_run_id IS NOT NULL)
                OR (stage = 'download' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND document_id IS NOT NULL)
              ),
              CHECK (work_identity_schema_version > 0),
              CHECK (input_schema_version > 0),
              CHECK (checkpoint_schema_version > 0)
            )
            SQL,
            'CREATE INDEX prc_process_work_crawl_block_idx ON prc_process_work (crawl_block_id) WHERE crawl_block_id IS NOT NULL',
            'CREATE INDEX prc_process_work_procurement_idx ON prc_process_work (procurement_id) WHERE procurement_id IS NOT NULL',
            'CREATE INDEX prc_process_work_document_idx ON prc_process_work (document_id) WHERE document_id IS NOT NULL',
            'CREATE INDEX prc_process_work_document_version_idx ON prc_process_work (document_version_id) WHERE document_version_id IS NOT NULL',
            'CREATE INDEX prc_process_work_analysis_window_idx ON prc_process_work (analysis_window_id) WHERE analysis_window_id IS NOT NULL',
            'CREATE INDEX prc_process_work_analysis_run_idx ON prc_process_work (analysis_run_id) WHERE analysis_run_id IS NOT NULL',
            "CREATE INDEX prc_process_work_due_idx ON prc_process_work (due_at, priority, id) WHERE state IN ('ready','deferred')",
            "CREATE INDEX prc_process_work_lease_idx ON prc_process_work (lease_until, id) WHERE state = 'running'",
            "CREATE INDEX prc_process_work_claim_idx ON prc_process_work (dispatch_claim_until, id) WHERE state = 'admitted'",
            "CREATE INDEX prc_process_work_fairness_idx ON prc_process_work (stage, cohort_key, created_at, id) WHERE state IN ('ready','deferred')",
            'CREATE INDEX prc_process_work_run_idx ON prc_process_work (origin_run_id, stage, state)',
            // Отдельный DB-clock reconciliation schedule для blocked(provider_result_unknown), вне business due.
            "CREATE INDEX prc_process_work_provider_recovery_due_idx ON prc_process_work (provider_recovery_due_at, id) WHERE state = 'blocked' AND reason_code = 'provider_result_unknown'",
            <<<'SQL'
            CREATE TABLE prc_process_attempts (
              id uuid PRIMARY KEY,
              work_id uuid NOT NULL REFERENCES prc_process_work(id),
              generation bigint NOT NULL CHECK (generation >= 1),
              owner_token_fingerprint bytea NOT NULL CHECK (octet_length(owner_token_fingerprint)=32),
              handler_version text NOT NULL,
              fault_signature bytea NULL CHECK (fault_signature IS NULL OR octet_length(fault_signature)=32),
              fault_policy_checked_at timestamptz NULL,
              started_at timestamptz NOT NULL,
              finished_at timestamptz NULL,
              last_heartbeat_at timestamptz NULL,
              last_checkpoint_at timestamptz NULL,
              terminal_kind text NULL CHECK (terminal_kind IN ('succeeded','partial','deferred','blocked','failed_item','interrupted','cancelled','superseded','lost_ownership')),
              worker_host text NOT NULL,
              worker_pid integer NOT NULL,
              CONSTRAINT prc_process_attempts_work_generation_unique UNIQUE (work_id, generation),
              CONSTRAINT prc_attempt_work_pair UNIQUE (id, work_id)
            )
            SQL,
            'CREATE INDEX prc_process_attempts_fault_idx ON prc_process_attempts (handler_version, fault_signature, finished_at) WHERE fault_signature IS NOT NULL',
            // handler_fault_budget: authority — finished faulted attempts exact(work_id,handler_version), без counter.
            'CREATE INDEX prc_process_attempts_work_fault_idx ON prc_process_attempts (work_id, handler_version) WHERE fault_signature IS NOT NULL AND finished_at IS NOT NULL',
            'CREATE INDEX prc_process_attempts_fault_policy_pending_idx ON prc_process_attempts (finished_at, id) WHERE fault_signature IS NOT NULL AND fault_policy_checked_at IS NULL',
            <<<'SQL'
            CREATE TABLE prc_admission_groups (
              id uuid PRIMARY KEY,
              work_id uuid NOT NULL REFERENCES prc_process_work(id),
              dispatch_generation bigint NOT NULL CHECK (dispatch_generation >= 1),
              required_lane_set_hash bytea NOT NULL CHECK (octet_length(required_lane_set_hash)=32),
              attempt_id uuid NULL REFERENCES prc_process_attempts(id),
              state text NOT NULL CHECK (state IN ('admitted','running','released')),
              version bigint NOT NULL DEFAULT 1 CHECK (version >= 1),
              admitted_at timestamptz NOT NULL,
              released_at timestamptz NULL,
              release_reason text NULL
            )
            SQL,
            "CREATE UNIQUE INDEX prc_admission_groups_active_work_unique ON prc_admission_groups (work_id) WHERE state <> 'released'",
            // Release удаляет reservations и переводит group в released одной транзакцией; CASCADE ограничен составными reservations.
            <<<'SQL'
            CREATE TABLE prc_process_reservations (
              admission_group_id uuid NOT NULL REFERENCES prc_admission_groups(id) ON DELETE CASCADE,
              lane_id uuid NOT NULL REFERENCES prc_process_lanes(id),
              work_id uuid NOT NULL REFERENCES prc_process_work(id),
              stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')),
              state text NOT NULL CHECK (state IN ('admitted','running')),
              PRIMARY KEY (admission_group_id, lane_id)
            )
            SQL,
            'CREATE INDEX prc_process_reservations_lane_state_idx ON prc_process_reservations (lane_id, state)',
            <<<'SQL'
            CREATE TABLE prc_process_run_work (
              run_id uuid NOT NULL REFERENCES prc_process_runs(id),
              work_id uuid NOT NULL REFERENCES prc_process_work(id),
              relation text NOT NULL CHECK (relation IN ('created','discovered','observes')),
              accounting_role text NOT NULL CHECK (accounting_role IN ('required','observes')),
              PRIMARY KEY (run_id, work_id)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_process_transitions (
              event_id uuid PRIMARY KEY,
              entity_kind text NOT NULL CHECK (entity_kind IN ('work','run','control','incident')),
              work_id uuid NULL REFERENCES prc_process_work(id),
              run_id uuid NULL REFERENCES prc_process_runs(id),
              control_id uuid NULL REFERENCES prc_control_scopes(id),
              incident_id uuid NULL REFERENCES prc_process_incidents(id),
              entity_seq bigint NOT NULL CHECK (entity_seq >= 1),
              previous_state text NULL,
              new_state text NOT NULL,
              reason_code text NULL,
              actor_kind text NOT NULL CHECK (actor_kind IN ('system','operator','worker','reconciler')),
              actor_id bigint NULL,
              generation bigint NULL,
              details jsonb NULL,
              details_schema_version smallint NULL,
              occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
              schema_version smallint NOT NULL,
              CONSTRAINT prc_process_transitions_details_schema_check CHECK ((details IS NULL) = (details_schema_version IS NULL)),
              CONSTRAINT prc_process_transitions_entity_check CHECK (
                num_nonnulls(work_id, run_id, control_id, incident_id) = 1 AND
                ((entity_kind='work' AND work_id IS NOT NULL) OR (entity_kind='run' AND run_id IS NOT NULL) OR
                 (entity_kind='control' AND control_id IS NOT NULL) OR (entity_kind='incident' AND incident_id IS NOT NULL))
              ),
              CHECK (details_schema_version > 0),
              CHECK (schema_version > 0)
            )
            SQL,
            'CREATE UNIQUE INDEX prc_process_transitions_work_seq_unique ON prc_process_transitions (work_id, entity_seq) WHERE work_id IS NOT NULL',
            'CREATE UNIQUE INDEX prc_process_transitions_run_seq_unique ON prc_process_transitions (run_id, entity_seq) WHERE run_id IS NOT NULL',
            'CREATE UNIQUE INDEX prc_process_transitions_control_seq_unique ON prc_process_transitions (control_id, entity_seq) WHERE control_id IS NOT NULL',
            'CREATE UNIQUE INDEX prc_process_transitions_incident_seq_unique ON prc_process_transitions (incident_id, entity_seq) WHERE incident_id IS NOT NULL',
            'CREATE INDEX prc_process_transitions_work_time_idx ON prc_process_transitions (work_id, occurred_at, event_id)',
            'CREATE INDEX prc_process_transitions_run_time_idx ON prc_process_transitions (run_id, occurred_at, event_id)',
            <<<'SQL'
            CREATE TABLE prc_process_outbox (
              message_id uuid PRIMARY KEY,
              dedup_key text NOT NULL,
              kind text NOT NULL CHECK (kind IN ('work_wake','feeder_wake','projection')),
              payload jsonb NOT NULL,
              payload_schema_version smallint NOT NULL,
              available_at timestamptz NOT NULL,
              claim_token uuid NULL,
              claim_until timestamptz NULL,
              published_at timestamptz NULL,
              attempts bigint NOT NULL DEFAULT 0 CHECK (attempts >= 0),
              last_error_code text NULL,
              CONSTRAINT prc_process_outbox_dedup_key_unique UNIQUE (dedup_key),
              CHECK (payload_schema_version > 0)
            )
            SQL,
            'CREATE INDEX prc_process_outbox_pending_idx ON prc_process_outbox (kind, available_at, message_id) WHERE published_at IS NULL',
            <<<'SQL'
            CREATE TABLE prc_process_daemon_heartbeats (
              instance_id uuid PRIMARY KEY,
              daemon text NOT NULL CHECK (daemon IN ('relay','reconcile','feed','project')),
              host text NOT NULL,
              pid integer NOT NULL,
              started_at timestamptz NOT NULL,
              last_tick_at timestamptz NOT NULL,
              last_error_code text NULL
            )
            SQL,
        ];
    }
};
