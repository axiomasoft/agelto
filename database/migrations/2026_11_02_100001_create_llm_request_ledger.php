<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Permanent additive carriers; legacy LlmCall remains on llm_calls until P3.13. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE llm_budget_accounts (
                account_id text NOT NULL, currency text NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
                unknown_count_threshold bigint NOT NULL DEFAULT 1 CHECK(unknown_count_threshold>=1),
                hard_exposure_cap numeric(24,8) NULL CHECK(hard_exposure_cap>=0),
                policy_version bigint NOT NULL DEFAULT 1 CHECK(policy_version>=1),
                policy_actor text NOT NULL, policy_reason text NOT NULL,
                PRIMARY KEY(account_id,currency), UNIQUE(account_id)
            );
            CREATE TABLE llm_budget_periods (
                id uuid PRIMARY KEY, account_id text NOT NULL, currency text NOT NULL,
                period_start timestamptz NOT NULL, period_end timestamptz NOT NULL,
                cap numeric(24,8) NOT NULL CHECK(cap>=0), spent numeric(24,8) NOT NULL DEFAULT 0 CHECK(spent>=0),
                reserved numeric(24,8) NOT NULL DEFAULT 0 CHECK(reserved>=0),
                CHECK(period_end>period_start), CHECK(spent+reserved<=cap),
                UNIQUE(account_id,currency,period_start,period_end),
                FOREIGN KEY(account_id,currency) REFERENCES llm_budget_accounts(account_id,currency)
            );
            CREATE TABLE llm_provider_requests (
                logical_request_id uuid PRIMARY KEY,
                logical_key bytea NOT NULL UNIQUE CHECK(octet_length(logical_key)=32),
                recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32),
                input_hash bytea NOT NULL CHECK(octet_length(input_hash)=32),
                purpose text NOT NULL CHECK(purpose IN ('generate','cache_create','cache_refresh','cache_storage','file_upload','lookup')),
                semantic_reask_ordinal bigint NOT NULL CHECK(semantic_reask_ordinal BETWEEN 0 AND 1),
                replay_intent_id uuid NOT NULL, replay_of uuid NULL REFERENCES llm_provider_requests(logical_request_id),
                command_id uuid NULL UNIQUE, replay_actor text NULL, replay_reason text NULL,
                state text NOT NULL CHECK(state IN ('prepared','sending','completed','failed_known','unknown','abandoned_unknown')),
                request_epoch bigint NOT NULL CHECK(request_epoch>=1), idempotency_key text NULL,
                consumer_correlation_id text NOT NULL, reservation_amount numeric(24,8) NOT NULL DEFAULT 0 CHECK(reservation_amount>=0),
                context_json jsonb NOT NULL, context_schema_version smallint NOT NULL CHECK(context_schema_version=1),
                max_physical_attempts bigint NOT NULL CHECK(max_physical_attempts BETWEEN 1 AND 10),
                CHECK((replay_of IS NULL)=(command_id IS NULL)),
                CHECK((command_id IS NULL)=(replay_actor IS NULL)), CHECK((command_id IS NULL)=(replay_reason IS NULL))
            );
            CREATE TABLE llm_provider_calls (
                physical_call_id uuid PRIMARY KEY,
                logical_request_id uuid NOT NULL REFERENCES llm_provider_requests(logical_request_id),
                ordinal bigint NOT NULL CHECK(ordinal>=1), provider_id bigint NOT NULL REFERENCES llm_providers(id),
                account_id text NOT NULL, api_surface text NOT NULL, model text NOT NULL, resolved_model_version text NULL,
                request_epoch bigint NOT NULL CHECK(request_epoch>=1), owner_attempt_id uuid NOT NULL,
                owner_work_generation bigint NOT NULL CHECK(owner_work_generation>=1), send_authorization_token uuid NOT NULL,
                send_claimed_at timestamptz NULL, sent_at timestamptz NULL, response_at timestamptz NULL,
                http_status smallint NULL CHECK(http_status BETWEEN 100 AND 599), finish_reason text NULL,
                usage_status text NOT NULL CHECK(usage_status IN ('known','unknown')),
                usage jsonb NULL, usage_schema_version smallint NULL CHECK(usage_schema_version>0),
                cost_estimate numeric(24,8) NOT NULL CHECK(cost_estimate>=0), cost_actual numeric(24,8) NULL CHECK(cost_actual>=0),
                receipt_id uuid NULL UNIQUE, receipt_state text NOT NULL CHECK(receipt_state IN ('pending','verified','not_sent','unknown')),
                receipt_upload_key text NOT NULL UNIQUE, receipt_disk text NULL,
                receipt_key text NULL, receipt_hash bytea NULL CHECK(octet_length(receipt_hash)=32),
                receipt_schema_version smallint NULL CHECK(receipt_schema_version=1), reservation_id uuid NOT NULL UNIQUE,
                outcome text NULL CHECK(outcome IN ('not_sent','success','known_nonbillable_cache_miss','known_retryable_nonbillable','billed_error','unknown')),
                charge_evidence text NULL,
                CHECK((usage IS NULL)=(usage_schema_version IS NULL)),
                CHECK((receipt_key IS NULL)=(receipt_hash IS NULL)), CHECK((receipt_key IS NULL)=(receipt_schema_version IS NULL)),
                CHECK((receipt_state='verified')=(receipt_key IS NOT NULL)),
                CHECK(receipt_state NOT IN ('verified','unknown') OR send_claimed_at IS NOT NULL),
                CHECK(receipt_state<>'not_sent' OR (send_claimed_at IS NULL AND cost_actual=0)),
                CHECK(receipt_key IS NULL OR receipt_key=receipt_upload_key),
                UNIQUE(logical_request_id,ordinal), UNIQUE(physical_call_id,logical_request_id)
            );
            CREATE TABLE llm_budget_reservations (
                id uuid PRIMARY KEY, operation_id uuid NOT NULL UNIQUE REFERENCES llm_provider_calls(physical_call_id),
                period_id uuid NOT NULL REFERENCES llm_budget_periods(id), upper_bound numeric(24,8) NOT NULL CHECK(upper_bound>=0),
                state text NOT NULL CHECK(state IN ('reserved','settled')), settled_amount numeric(24,8) NULL,
                pricing_snapshot jsonb NOT NULL, pricing_snapshot_schema_version smallint NOT NULL CHECK(pricing_snapshot_schema_version=1),
                CHECK((state='settled')=(settled_amount IS NOT NULL)), CHECK(settled_amount BETWEEN 0 AND upper_bound),
                UNIQUE(id,operation_id)
            );
            ALTER TABLE llm_provider_calls ADD CONSTRAINT llm_provider_calls_reservation_fk
                FOREIGN KEY(reservation_id,physical_call_id) REFERENCES llm_budget_reservations(id,operation_id) DEFERRABLE INITIALLY DEFERRED;
            CREATE TABLE prc_analysis_llm_requests (
                id uuid PRIMARY KEY, analysis_run_id uuid NOT NULL REFERENCES prc_analysis_runs(id),
                work_id uuid NOT NULL REFERENCES prc_process_work(id), attempt_id uuid NOT NULL REFERENCES prc_process_attempts(id),
                logical_request_id uuid NOT NULL REFERENCES llm_provider_requests(logical_request_id),
                physical_call_id uuid NOT NULL UNIQUE REFERENCES llm_provider_calls(physical_call_id),
                expected_input_hash bytea NOT NULL CHECK(octet_length(expected_input_hash)=32),
                recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32), recovery_active boolean NOT NULL DEFAULT true,
                FOREIGN KEY(physical_call_id,logical_request_id) REFERENCES llm_provider_calls(physical_call_id,logical_request_id),
                FOREIGN KEY(analysis_run_id,recipe_hash) REFERENCES prc_analysis_runs(id,recipe_hash)
            );
            CREATE INDEX prc_analysis_llm_work_idx ON prc_analysis_llm_requests(work_id,attempt_id);
            CREATE UNIQUE INDEX prc_analysis_llm_active_work ON prc_analysis_llm_requests(work_id) WHERE recovery_active;
            CREATE INDEX llm_calls_account_idx ON llm_provider_calls(account_id,logical_request_id);
            CREATE TABLE llm_financial_actions (
                id uuid PRIMARY KEY, account_id text NOT NULL, currency text NOT NULL,
                physical_call_id uuid NULL REFERENCES llm_provider_calls(physical_call_id),
                action text NOT NULL CHECK(action IN ('policy_change','conservative_settlement','abandon_unknown','receipt_reconciliation')),
                actor text NOT NULL CHECK(length(actor)>0), reason text NOT NULL CHECK(length(reason)>0),
                details jsonb NOT NULL, details_schema_version smallint NOT NULL CHECK(details_schema_version=1),
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(account_id,currency) REFERENCES llm_budget_accounts(account_id,currency)
            );
            CREATE OR REPLACE FUNCTION llm_request_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    RAISE EXCEPTION 'Financial provenance is immutable' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='llm_provider_requests' THEN
                    IF (to_jsonb(OLD)-ARRAY['state','request_epoch','reservation_amount']) IS DISTINCT FROM
                       (to_jsonb(NEW)-ARRAY['state','request_epoch','reservation_amount']) THEN
                        RAISE EXCEPTION 'Logical request identity is immutable' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='llm_provider_calls' THEN
                    IF (to_jsonb(OLD)-ARRAY['send_claimed_at','sent_at','response_at','resolved_model_version','http_status','finish_reason','usage_status','usage','usage_schema_version','cost_actual','receipt_state','receipt_key','receipt_hash','receipt_schema_version','outcome','charge_evidence']) IS DISTINCT FROM
                       (to_jsonb(NEW)-ARRAY['send_claimed_at','sent_at','response_at','resolved_model_version','http_status','finish_reason','usage_status','usage','usage_schema_version','cost_actual','receipt_state','receipt_key','receipt_hash','receipt_schema_version','outcome','charge_evidence'])
                       OR (OLD.send_claimed_at IS NOT NULL AND OLD.send_claimed_at IS DISTINCT FROM NEW.send_claimed_at)
                       OR (OLD.receipt_state='verified' AND OLD IS DISTINCT FROM NEW) THEN
                        RAISE EXCEPTION 'Physical call identity/receipt is immutable' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='llm_budget_reservations' THEN
                    IF (to_jsonb(OLD)-ARRAY['state','settled_amount']) IS DISTINCT FROM (to_jsonb(NEW)-ARRAY['state','settled_amount'])
                        OR (OLD.state='settled' AND OLD IS DISTINCT FROM NEW AND NOT EXISTS (
                            SELECT 1 FROM llm_financial_actions a WHERE a.physical_call_id=NEW.operation_id AND a.action='receipt_reconciliation'
                            AND (a.details->>'previous_amount')::numeric=OLD.settled_amount AND (a.details->>'settled_amount')::numeric=NEW.settled_amount
                        )) THEN
                        RAISE EXCEPTION 'Financial reservation identity is immutable' USING ERRCODE='23514';
                    END IF;
                ELSIF (to_jsonb(OLD)-'recovery_active') IS DISTINCT FROM (to_jsonb(NEW)-'recovery_active') THEN
                    RAISE EXCEPTION 'Consumer linkage is immutable' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER llm_request_identity BEFORE UPDATE OR DELETE ON llm_provider_requests FOR EACH ROW EXECUTE FUNCTION llm_request_identity_guard();
            CREATE TRIGGER llm_call_identity BEFORE UPDATE OR DELETE ON llm_provider_calls FOR EACH ROW EXECUTE FUNCTION llm_request_identity_guard();
            CREATE TRIGGER prc_analysis_llm_identity BEFORE UPDATE OR DELETE ON prc_analysis_llm_requests FOR EACH ROW EXECUTE FUNCTION llm_request_identity_guard();
            CREATE TRIGGER llm_financial_action_immutable BEFORE UPDATE OR DELETE ON llm_financial_actions FOR EACH ROW EXECUTE FUNCTION llm_request_identity_guard();
            CREATE TRIGGER llm_reservation_immutable BEFORE UPDATE OR DELETE ON llm_budget_reservations FOR EACH ROW EXECUTE FUNCTION llm_request_identity_guard();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE prc_analysis_llm_requests,llm_financial_actions');
        DB::statement('ALTER TABLE llm_provider_calls DROP CONSTRAINT llm_provider_calls_reservation_fk');
        DB::statement('DROP TABLE llm_budget_reservations,llm_provider_calls,llm_provider_requests,llm_budget_periods,llm_budget_accounts');
        DB::statement('DROP FUNCTION llm_request_identity_guard()');
    }
};
