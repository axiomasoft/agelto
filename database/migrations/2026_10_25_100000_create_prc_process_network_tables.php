<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P2.1: baseline network authority (platform-transport#s06, implementation-blueprint#bp-ddl-network).
 * Имя файла сортируется сразу после Process ledger и до `..._100001_create_prc_request_observation_tables`:
 * request attempts объявляют FK на routes/targets в своём CREATE, поэтому эти таблицы создаются раньше.
 *
 * Порядок FK: routes/profiles → source targets → health rows → outbound operation owner → budgets →
 * demands → permits → permit scopes. Новые identities uuid, platform_id остаётся bigint.
 * `prc_network_permit_scopes.permit_id` — единственное composition-child исключение CASCADE (D27);
 * все остальные FK NO ACTION. Demand owner — ровно один из (work+attempt+generation) или
 * (operation+generation): CHECK явно проверяет IS NOT NULL, SQL UNKNOWN не пропускает половину owner.
 *
 * Активация (permits/health writers, cutover callers) — P2.2–P2.6; catalog rows создаёт только
 * явный `platform:sync-catalog`, не migration/seed и не boot.
 */
return new class extends Migration
{
    private const array TABLES = [
        'prc_network_routes', 'prc_network_route_profiles', 'prc_network_route_profile_members',
        'prc_source_targets', 'prc_route_health', 'prc_route_target_health', 'prc_target_health', 'prc_adapter_health',
        'prc_outbound_operations', 'prc_outbound_operation_controls', 'prc_network_budgets',
        'prc_network_demands', 'prc_network_permits', 'prc_network_permit_scopes',
    ];

    public function up(): void
    {
        foreach ($this->statements() as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prc_process_attempts DROP COLUMN adapter_health_id, DROP COLUMN adapter_health_epoch');
        DB::statement('ALTER TABLE prc_crawlers DROP CONSTRAINT prc_crawlers_route_profile_id_foreign');
        DB::statement('ALTER TABLE prc_proxies DROP COLUMN route_id');
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /** @return list<string> */
    private function statements(): array
    {
        return [
            // Direct/proxy identity. egress_id стабилен для workers одной NAT; independence — metadata.
            <<<'SQL'
            CREATE TABLE prc_network_routes (
              id uuid PRIMARY KEY,
              route_key text NOT NULL UNIQUE,
              kind text NOT NULL CHECK(kind IN ('direct','proxy')),
              egress_id text NOT NULL,
              independence_group text NULL,
              enabled boolean NOT NULL,
              disabled_at timestamptz NULL,
              disabled_reason text NULL,
              secret_ref text NULL,
              credential_version bigint NOT NULL CHECK(credential_version>=1)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_network_route_profiles (
              id uuid PRIMARY KEY,
              profile_key text NOT NULL UNIQUE,
              policy_version bigint NOT NULL CHECK(policy_version>=1)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_network_route_profile_members (
              profile_id uuid NOT NULL REFERENCES prc_network_route_profiles(id),
              route_id uuid NOT NULL REFERENCES prc_network_routes(id),
              PRIMARY KEY(profile_id,route_id)
            )
            SQL,
            // Logical capability target; origin budget identity — canonical_origin, не target_key.
            <<<'SQL'
            CREATE TABLE prc_source_targets (
              id uuid PRIMARY KEY,
              platform_id bigint NULL REFERENCES prc_platforms(id),
              provider_key text NULL,
              target_key text NOT NULL,
              canonical_origin text NOT NULL,
              capability text NOT NULL,
              endpoint_policy jsonb NOT NULL,
              endpoint_policy_schema_version smallint NOT NULL,
              policy_version bigint NOT NULL CHECK(policy_version>=1),
              retired_at timestamptz NULL,
              UNIQUE(platform_id,target_key),
              UNIQUE(provider_key,target_key),
              CHECK ((platform_id IS NOT NULL AND provider_key IS NULL) OR (platform_id IS NULL AND provider_key IS NOT NULL AND provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$')),
              CHECK (endpoint_policy_schema_version > 0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_route_health (
              id uuid PRIMARY KEY,
              route_id uuid NOT NULL UNIQUE REFERENCES prc_network_routes(id),
              state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')),
              health_epoch bigint NOT NULL CHECK(health_epoch>=1),
              state_version bigint NOT NULL CHECK(state_version>=1),
              reason_code text NULL,
              last_success_at timestamptz NULL,
              last_failure_at timestamptz NULL,
              open_until timestamptz NULL,
              probe_token uuid NULL,
              probe_until timestamptz NULL,
              stable_since timestamptz NULL,
              recent_openings bigint NOT NULL CHECK(recent_openings>=0),
              consecutive_failures bigint NOT NULL CHECK(consecutive_failures>=0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_route_target_health (
              id uuid PRIMARY KEY,
              route_id uuid NOT NULL REFERENCES prc_network_routes(id),
              target_id uuid NOT NULL REFERENCES prc_source_targets(id),
              state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')),
              health_epoch bigint NOT NULL CHECK(health_epoch>=1),
              state_version bigint NOT NULL CHECK(state_version>=1),
              reason_code text NULL,
              last_success_at timestamptz NULL,
              last_failure_at timestamptz NULL,
              open_until timestamptz NULL,
              probe_token uuid NULL,
              probe_until timestamptz NULL,
              stable_since timestamptz NULL,
              recent_openings bigint NOT NULL CHECK(recent_openings>=0),
              consecutive_failures bigint NOT NULL CHECK(consecutive_failures>=0),
              UNIQUE(route_id,target_id)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_target_health (
              id uuid PRIMARY KEY,
              target_id uuid NOT NULL UNIQUE REFERENCES prc_source_targets(id),
              state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')),
              availability text NOT NULL CHECK(availability IN ('unknown','available','suspect','unavailable')),
              health_epoch bigint NOT NULL CHECK(health_epoch>=1),
              state_version bigint NOT NULL CHECK(state_version>=1),
              reason_code text NULL,
              last_success_at timestamptz NULL,
              last_failure_at timestamptz NULL,
              open_until timestamptz NULL,
              probe_token uuid NULL,
              probe_until timestamptz NULL,
              stable_since timestamptz NULL,
              recent_openings bigint NOT NULL CHECK(recent_openings>=0)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_adapter_health (
              id uuid PRIMARY KEY,
              platform_id bigint NOT NULL REFERENCES prc_platforms(id),
              capability text NOT NULL,
              adapter_version text NOT NULL,
              contract_version text NOT NULL,
              state text NOT NULL CHECK(state IN ('healthy','suspect','held','validating')),
              health_epoch bigint NOT NULL CHECK(health_epoch>=1),
              state_version bigint NOT NULL CHECK(state_version>=1),
              reason_code text NULL,
              last_success_at timestamptz NULL,
              last_failure_at timestamptz NULL,
              open_until timestamptz NULL,
              probe_token uuid NULL,
              probe_until timestamptz NULL,
              stable_since timestamptz NULL,
              recent_openings bigint NOT NULL CHECK(recent_openings>=0),
              UNIQUE(platform_id,capability,adapter_version,contract_version)
            )
            SQL,
            <<<'SQL'
            ALTER TABLE prc_process_attempts
              ADD COLUMN adapter_health_id uuid NULL REFERENCES prc_adapter_health(id),
              ADD COLUMN adapter_health_epoch bigint NULL CHECK(adapter_health_epoch IS NULL OR adapter_health_epoch >= 1),
              ADD CONSTRAINT prc_process_attempts_adapter_pair CHECK((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL))
            SQL,
            'CREATE INDEX prc_process_attempts_adapter_live ON prc_process_attempts(adapter_health_id, adapter_health_epoch) WHERE finished_at IS NULL AND adapter_health_id IS NOT NULL',
            // Bounded OUTBOUND owner для legacy_bridge/diagnostic/probe — не второй process engine.
            <<<'SQL'
            CREATE TABLE prc_outbound_operations (
              id uuid PRIMARY KEY,
              operation_key uuid NOT NULL UNIQUE,
              kind text NOT NULL CHECK(kind IN ('legacy_bridge','diagnostic','probe')),
              platform_id bigint NULL REFERENCES prc_platforms(id),
              provider_key text NULL CHECK(provider_key IS NULL OR provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$'),
              CHECK (platform_id IS NULL OR provider_key IS NULL),
              adapter_health_id uuid NULL REFERENCES prc_adapter_health(id),
              adapter_health_epoch bigint NULL CHECK(adapter_health_epoch IS NULL OR adapter_health_epoch >= 1),
              CHECK((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL)),
              parent_attempt_id uuid NULL REFERENCES prc_process_attempts(id),
              CHECK(parent_attempt_id IS NULL OR kind='probe'),
              caller_code text NOT NULL,
              state text NOT NULL CHECK(state IN ('open','closed','expired')),
              owner_token uuid NOT NULL,
              generation bigint NOT NULL CHECK(generation>=1),
              lease_until timestamptz NOT NULL,
              deadline_at timestamptz NOT NULL,
              created_at timestamptz NOT NULL,
              finished_at timestamptz NULL,
              probe_token uuid NULL,
              policy_hash bytea NOT NULL CHECK(octet_length(policy_hash)=32),
              CHECK(lease_until<=deadline_at),
              CHECK((kind='probe') = (probe_token IS NOT NULL)),
              CHECK((state='open') = (finished_at IS NULL))
            )
            SQL,
            "CREATE INDEX prc_outbound_operations_reap ON prc_outbound_operations(lease_until) WHERE state='open'",
            "CREATE INDEX prc_outbound_operations_adapter_live ON prc_outbound_operations(adapter_health_id, adapter_health_epoch, lease_until) WHERE state='open' AND adapter_health_id IS NOT NULL",
            <<<'SQL'
            CREATE TABLE prc_outbound_operation_controls (
              operation_id uuid NOT NULL REFERENCES prc_outbound_operations(id),
              control_scope_id uuid NOT NULL REFERENCES prc_control_scopes(id),
              control_version bigint NOT NULL,
              PRIMARY KEY(operation_id,control_scope_id)
            )
            SQL,
            // GCRA authority: tat/send_tat/not_before никогда не перематываются catalog sync/override.
            <<<'SQL'
            CREATE TABLE prc_network_budgets (
              id uuid PRIMARY KEY,
              scope_key text NOT NULL UNIQUE,
              emission_interval_us bigint NOT NULL CHECK(emission_interval_us>0),
              burst smallint NOT NULL DEFAULT 1 CHECK(burst>=1),
              max_inflight smallint NOT NULL CHECK(max_inflight>=1),
              tat timestamptz NOT NULL DEFAULT '-infinity',
              send_tat timestamptz NOT NULL DEFAULT '-infinity',
              not_before timestamptz NOT NULL DEFAULT '-infinity',
              policy_version bigint NOT NULL CHECK(policy_version>=1)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE prc_network_demands (
              id uuid PRIMARY KEY,
              physical_request_id uuid NOT NULL UNIQUE,
              work_id uuid NULL REFERENCES prc_process_work(id),
              attempt_id uuid NULL,
              work_generation bigint NULL,
              operation_id uuid NULL REFERENCES prc_outbound_operations(id),
              operation_generation bigint NULL,
              origin_budget_id uuid NOT NULL REFERENCES prc_network_budgets(id),
              route_id uuid NOT NULL REFERENCES prc_network_routes(id),
              target_id uuid NOT NULL REFERENCES prc_source_targets(id),
              traffic_class text NOT NULL CHECK(traffic_class IN ('fresh_listing','status_refresh','detail','archive_listing','download','reference_sync','diagnostic','provider')),
              purpose text NOT NULL CHECK(purpose IN ('ordinary','probe','diagnostic')),
              ticket_seq bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
              registered_at timestamptz NOT NULL,
              renewed_at timestamptz NOT NULL,
              expires_at timestamptz NOT NULL,
              operation_deadline timestamptz NOT NULL,
              state text NOT NULL CHECK(state IN ('queued','granted','cancelled','expired')),
              FOREIGN KEY(attempt_id,work_id) REFERENCES prc_process_attempts(id,work_id),
              CHECK((work_id IS NOT NULL AND attempt_id IS NOT NULL AND work_generation IS NOT NULL AND work_generation>=1 AND operation_id IS NULL AND operation_generation IS NULL)
                 OR (work_id IS NULL AND attempt_id IS NULL AND work_generation IS NULL AND operation_id IS NOT NULL AND operation_generation IS NOT NULL AND operation_generation>=1)),
              CHECK(expires_at<=operation_deadline)
            )
            SQL,
            "CREATE UNIQUE INDEX prc_network_demands_attempt_active ON prc_network_demands(attempt_id) WHERE state='queued' AND attempt_id IS NOT NULL",
            "CREATE UNIQUE INDEX prc_network_demands_operation_active ON prc_network_demands(operation_id) WHERE state='queued' AND operation_id IS NOT NULL",
            "CREATE INDEX prc_network_demands_fifo ON prc_network_demands(origin_budget_id,ticket_seq) WHERE state='queued'",
            'CREATE INDEX prc_network_demands_class ON prc_network_demands(origin_budget_id,traffic_class,id)',
            <<<'SQL'
            CREATE TABLE prc_network_permits (
              id uuid PRIMARY KEY,
              demand_id uuid NOT NULL UNIQUE REFERENCES prc_network_demands(id),
              slot_at timestamptz NOT NULL,
              expires_at timestamptz NOT NULL,
              authorized_at timestamptz NULL,
              state text NOT NULL CHECK(state IN ('reserved','sent','released','expired')),
              policy_versions jsonb NOT NULL,
              policy_schema_version smallint NOT NULL CHECK(policy_schema_version>=1),
              health_epochs jsonb NOT NULL,
              health_epochs_schema_version smallint NOT NULL CHECK(health_epochs_schema_version>=1),
              created_at timestamptz NOT NULL,
              CHECK(expires_at>slot_at),
              CHECK(state<>'reserved' OR authorized_at IS NULL),
              CHECK(state<>'sent' OR authorized_at IS NOT NULL)
            )
            SQL,
            "CREATE INDEX prc_network_permits_active ON prc_network_permits(expires_at) WHERE state IN ('reserved','sent')",
            'CREATE INDEX prc_network_permits_authorized ON prc_network_permits(authorized_at) WHERE authorized_at IS NOT NULL',
            // Composition child (D27): permit row владеет точным scope set; budgets — NO ACTION.
            <<<'SQL'
            CREATE TABLE prc_network_permit_scopes (
              permit_id uuid NOT NULL REFERENCES prc_network_permits(id) ON DELETE CASCADE,
              budget_id uuid NOT NULL REFERENCES prc_network_budgets(id),
              PRIMARY KEY(permit_id,budget_id)
            )
            SQL,
            'CREATE INDEX prc_network_permit_scopes_budget ON prc_network_permit_scopes(budget_id)',
            'ALTER TABLE prc_proxies ADD COLUMN route_id uuid NOT NULL UNIQUE REFERENCES prc_network_routes(id)',
            'ALTER TABLE prc_crawlers ADD CONSTRAINT prc_crawlers_route_profile_id_foreign FOREIGN KEY (route_profile_id) REFERENCES prc_network_route_profiles(id)',
        ];
    }
};
