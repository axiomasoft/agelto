<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

/** Read-only schema/document boundary. This never connects to, migrates or resets a database. */
final class SchemaInventory
{
    private const array REQUIRED_COLUMNS = [
        'prc_control_scopes' => 'id scope_kind scope_key parent_id desired_mode control_version changed_by change_reason changed_at',
        'prc_process_lanes' => 'id lane_key parent_lane_id max_outstanding max_running low_watermark refill_max policy_version fairness_cursor fairness_cursor_schema_version',
        'prc_process_runs' => 'id kind trigger_key profile_id configuration_snapshot configuration_schema_version configuration_hash requested_by requested_at started_at finished_at discovery_closed_at cancel_requested_at discovery_outcome discovery_reason_code transition_seq projection_version',
        'prc_process_incidents' => 'id fingerprint scope_key health_epoch kind state first_seen_at last_seen_at occurrences transition_seq evidence evidence_schema_version acknowledged_by resolved_at',
        'prc_process_handler_holds' => 'id stage handler_version state version fault_signature incident_id held_at',
        'prc_crawl_runs' => 'id process_run_id listing_kind filter_payload filter_schema_version filter_hash access_policy_hash adapter_version',
        'prc_crawl_blocks' => 'id crawl_run_id parent_block_id block_key partition_payload partition_schema_version filter_hash head_generation input_revision cursor cursor_schema_version terminal_proof terminal_proof_schema_version limitation_reason_code',
        'prc_operation_intents' => 'id intent_key stage subject_kind subject_id semantic_policy_hash access_policy_hash cycle_seq expected_head_generation state requested_at next_due_at',
        'prc_process_work' => 'id stage work_key work_identity work_identity_schema_version intent_id input_revision input_hash input_payload input_schema_version state reason_code due_at provider_recovery_due_at priority platform_id cohort_key origin_run_id crawl_block_id procurement_id document_id document_version_id analysis_window_id analysis_run_id generation owner_token lease_until heartbeat_at dispatch_generation dispatch_claim_until checkpoint checkpoint_schema_version checkpoint_seq execution_attempts transport_attempts semantic_reasks item_failures infrastructure_interruptions transition_seq created_at updated_at',
        'prc_process_attempts' => 'id work_id generation owner_token_fingerprint handler_version fault_signature fault_policy_checked_at started_at finished_at last_heartbeat_at last_checkpoint_at terminal_kind worker_host worker_pid',
        'prc_admission_groups' => 'id work_id dispatch_generation required_lane_set_hash attempt_id state version admitted_at released_at release_reason',
        'prc_process_reservations' => 'admission_group_id lane_id work_id stage state',
        'prc_process_run_work' => 'run_id work_id relation accounting_role',
        'prc_process_transitions' => 'event_id entity_kind work_id run_id control_id incident_id entity_seq previous_state new_state reason_code actor_kind actor_id generation details details_schema_version occurred_at schema_version',
        'prc_process_outbox' => 'message_id dedup_key kind payload payload_schema_version available_at claim_token claim_until published_at attempts last_error_code',
        'prc_process_daemon_heartbeats' => 'instance_id daemon host pid started_at last_tick_at last_error_code',
        'prc_outbound_operations' => 'id operation_key kind platform_id provider_key adapter_health_id adapter_health_epoch caller_code state owner_token generation lease_until deadline_at created_at finished_at probe_token policy_hash',
        'prc_outbound_operation_controls' => 'operation_id control_scope_id control_version',
        'prc_network_budgets' => 'id scope_key emission_interval_us burst max_inflight tat send_tat not_before policy_version',
        'prc_network_demands' => 'id physical_request_id work_id attempt_id work_generation operation_id operation_generation origin_budget_id route_id target_id traffic_class purpose ticket_seq registered_at renewed_at expires_at operation_deadline state',
        'prc_network_permits' => 'id demand_id slot_at expires_at authorized_at state policy_versions policy_schema_version health_epochs health_epochs_schema_version created_at',
        'prc_network_permit_scopes' => 'permit_id budget_id',
        'prc_request_attempts' => 'request_id attempt_id request_seq target_id route_id started_at sent_at finished_at phase outcome bytes status duration_ms epochs epochs_schema_version artifacts artifacts_schema_version',
        'prc_observations' => 'observation_id request_id attempt_id kind reason_code input_hash generation epochs epochs_schema_version stale details details_schema_version occurred_at',
        'prc_observation_applications' => 'observation_id projection_key reducer_version applied_at',
        'prc_process_projection_states' => 'projector_key mode active_generation catalog_hash history_complete history_pruned_at maintenance_token maintenance_started_at rebuilt_at',
        'prc_process_metric_totals' => 'projector_key generation metric_key dimension_key value updated_at',
        'prc_process_projection_receipts' => 'projector_key event_id applied_at',
        'prc_network_routes' => 'id route_key kind egress_id independence_group enabled disabled_at disabled_reason secret_ref credential_version',
        'prc_network_route_profiles' => 'id profile_key policy_version',
        'prc_source_targets' => 'id platform_id provider_key target_key canonical_origin capability endpoint_policy endpoint_policy_schema_version policy_version retired_at',
        'prc_route_health' => 'id route_id state health_epoch state_version reason_code last_success_at last_failure_at open_until probe_token probe_until stable_since recent_openings consecutive_failures',
        'prc_route_target_health' => 'id route_id target_id state health_epoch state_version reason_code last_success_at last_failure_at open_until probe_token probe_until stable_since recent_openings consecutive_failures',
        'prc_target_health' => 'id target_id state availability health_epoch state_version reason_code last_success_at last_failure_at open_until probe_token probe_until stable_since recent_openings',
        'prc_adapter_health' => 'id platform_id capability adapter_version contract_version state health_epoch state_version reason_code last_success_at last_failure_at open_until probe_token probe_until stable_since recent_openings',
        'prc_detail_snapshots' => 'id procurement_id input_revision part_key completeness result_ref result_ref_schema_version source_version created_at',
        'prc_status_refreshes' => 'id procurement_id intent_id cycle_seq next_due_at source_version observed_at',
        'prc_procurement_documents' => 'procurement_id document_id',
        'prc_storage_domains' => 'id domain_key capacity_bytes high_watermark_bytes accounted_durable_bytes policy_version',
        'prc_storage_reservations' => 'id domain_id work_id attempt_id operation_id upload_intent_id owner_token generation byte_upper_bound phase status cleanup_verified_at',
        'prc_document_blobs' => 'id storage_domain_id content_hash disk object_key size_bytes verified_at deletion_generation deletion_marked_at',
        'prc_analysis_runs' => 'id document_version_id representation_id representation_hash recipe_json recipe_schema_version recipe_hash generation expected_document_head_generation expected_analysis_generation requested_reason supersedes_run_id publication_status',
        'prc_analysis_windows' => 'id run_id window_key input_hash input_range input_range_schema_version expected_coverage expected_coverage_schema_version state checkpoint_version',
        'prc_analysis_chunks' => 'id run_id window_id window_key recipe_hash subrange_key output_ordinal output_ref output_ref_schema_version output_hash canonical_rows canonical_rows_schema_version provenance provenance_schema_version completed_at',
        'llm_provider_requests' => 'logical_request_id logical_key recipe_hash input_hash purpose semantic_reask_ordinal replay_intent_id replay_of command_id state request_epoch idempotency_key consumer_correlation_id reservation_amount max_physical_attempts',
        'llm_budget_accounts' => 'account_id currency unknown_count_threshold hard_exposure_cap policy_version policy_actor policy_reason',
        'llm_financial_actions' => 'id account_id currency physical_call_id action actor reason details details_schema_version occurred_at',
        'llm_budget_periods' => 'id account_id currency period_start period_end cap spent reserved',
        'llm_budget_reservations' => 'id operation_id period_id upper_bound state settled_amount pricing_snapshot pricing_snapshot_schema_version',
        'llm_provider_calls' => 'physical_call_id logical_request_id ordinal provider_id account_id api_surface model resolved_model_version request_epoch owner_attempt_id owner_work_generation send_authorization_token send_claimed_at sent_at response_at http_status finish_reason usage_status usage usage_schema_version cost_estimate cost_actual receipt_id receipt_state receipt_upload_key receipt_disk receipt_key receipt_hash receipt_schema_version reservation_id',
        'prc_analysis_llm_requests' => 'id analysis_run_id work_id attempt_id logical_request_id physical_call_id expected_input_hash recipe_hash',
        'llm_file_assets' => 'id provider_id account_id api_surface content_hash mime provider_uri state expires_at creation_token creation_lease_until',
        'llm_context_caches' => 'id provider_id account_id api_surface model system_hash schema_tool_policy_hash representation_hash content_ordering_hash provider_name expires_at creation_state creation_token creation_lease_until cost',
        'prc_network_route_profile_members' => 'profile_id route_id',
    ];

    private const array REQUIRED_FKS = [
        'prc_control_scopes.parent_id' => 'prc_control_scopes/id/P1.6/NO ACTION',
        'prc_control_scopes.changed_by' => 'users/id/P1.6/NO ACTION',
        'prc_process_lanes.parent_lane_id' => 'prc_process_lanes/id/P1.6/NO ACTION',
        'prc_process_runs.profile_id' => 'prc_crawlers/id/P1.6/NO ACTION',
        'prc_process_runs.requested_by' => 'users/id/P1.6/NO ACTION',
        'prc_process_incidents.acknowledged_by' => 'users/id/P1.6/NO ACTION',
        'prc_process_handler_holds.incident_id' => 'prc_process_incidents/id/P1.6/NO ACTION',
        'prc_crawl_runs.process_run_id' => 'prc_process_runs/id/P1.6/NO ACTION',
        'prc_crawl_blocks.crawl_run_id' => 'prc_crawl_runs/id/P1.6/NO ACTION',
        'prc_crawl_blocks.parent_block_id' => 'prc_crawl_blocks/id/P1.6/NO ACTION',
        'prc_process_work.intent_id' => 'prc_operation_intents/id/P1.6/NO ACTION',
        'prc_process_work.platform_id' => 'prc_platforms/id/P1.6/NO ACTION',
        'prc_process_work.origin_run_id' => 'prc_process_runs/id/P1.6/NO ACTION',
        'prc_process_work.crawl_block_id' => 'prc_crawl_blocks/id/P1.6/NO ACTION',
        'prc_process_work.procurement_id' => 'prc_procurements/id/P1.6/NO ACTION',
        'prc_process_work.document_id' => 'prc_documents/id/P1.6/NO ACTION',
        'prc_process_work.document_version_id' => 'prc_document_versions/id/P3.1/NO ACTION',
        'prc_process_work.analysis_window_id' => 'prc_analysis_windows/id/P3.8/NO ACTION',
        'prc_process_work.analysis_run_id' => 'prc_analysis_runs/id/P3.8/NO ACTION',
        'prc_process_attempts.work_id' => 'prc_process_work/id/P1.6/NO ACTION',
        'prc_admission_groups.work_id' => 'prc_process_work/id/P1.6/NO ACTION',
        'prc_admission_groups.attempt_id' => 'prc_process_attempts/id/P1.6/NO ACTION',
        'prc_process_reservations.admission_group_id' => 'prc_admission_groups/id/P1.6/CASCADE',
        'prc_process_reservations.lane_id' => 'prc_process_lanes/id/P1.6/NO ACTION',
        'prc_process_reservations.work_id' => 'prc_process_work/id/P1.6/NO ACTION',
        'prc_process_run_work.run_id' => 'prc_process_runs/id/P1.6/NO ACTION',
        'prc_process_run_work.work_id' => 'prc_process_work/id/P1.6/NO ACTION',
        'prc_process_transitions.work_id' => 'prc_process_work/id/P1.6/NO ACTION',
        'prc_process_transitions.run_id' => 'prc_process_runs/id/P1.6/NO ACTION',
        'prc_process_transitions.control_id' => 'prc_control_scopes/id/P1.6/NO ACTION',
        'prc_process_transitions.incident_id' => 'prc_process_incidents/id/P1.6/NO ACTION',
        'prc_outbound_operations.adapter_health_id' => 'prc_adapter_health/id/P2.6/NO ACTION',
        'prc_outbound_operations.platform_id' => 'prc_platforms/id/P2.1/NO ACTION',
        'prc_outbound_operation_controls.operation_id' => 'prc_outbound_operations/id/P2.1/NO ACTION',
        'prc_outbound_operation_controls.control_scope_id' => 'prc_control_scopes/id/P2.1/NO ACTION',
        'prc_network_demands.work_id' => 'prc_process_work/id/P2.1/NO ACTION',
        'prc_network_demands.operation_id' => 'prc_outbound_operations/id/P2.1/NO ACTION',
        'prc_network_demands.origin_budget_id' => 'prc_network_budgets/id/P2.1/NO ACTION',
        'prc_network_demands.route_id' => 'prc_network_routes/id/P2.1/NO ACTION',
        'prc_network_demands.target_id' => 'prc_source_targets/id/P2.1/NO ACTION',
        'prc_network_permits.demand_id' => 'prc_network_demands/id/P2.1/NO ACTION',
        'prc_network_permit_scopes.permit_id' => 'prc_network_permits/id/P2.1/CASCADE',
        'prc_network_permit_scopes.budget_id' => 'prc_network_budgets/id/P2.1/NO ACTION',
        'prc_request_attempts.attempt_id' => 'prc_process_attempts/id/P1.6/NO ACTION',
        'prc_request_attempts.target_id' => 'prc_source_targets/id/P2.1/NO ACTION',
        'prc_request_attempts.route_id' => 'prc_network_routes/id/P2.1/NO ACTION',
        'prc_observations.request_id' => 'prc_request_attempts/request_id/P1.6/NO ACTION',
        'prc_observations.attempt_id' => 'prc_process_attempts/id/P1.6/NO ACTION',
        'prc_observation_applications.observation_id' => 'prc_observations/observation_id/P1.6/NO ACTION',
        'prc_process_metric_totals.projector_key' => 'prc_process_projection_states/projector_key/P1.15/NO ACTION',
        'prc_process_projection_receipts.projector_key' => 'prc_process_projection_states/projector_key/P1.15/NO ACTION',
        'prc_process_projection_receipts.event_id' => 'prc_process_transitions/event_id/P1.15/RESTRICT',
        'prc_source_targets.platform_id' => 'prc_platforms/id/P2.1/NO ACTION',
        'prc_route_health.route_id' => 'prc_network_routes/id/P2.1/NO ACTION',
        'prc_route_target_health.route_id' => 'prc_network_routes/id/P2.1/NO ACTION',
        'prc_route_target_health.target_id' => 'prc_source_targets/id/P2.1/NO ACTION',
        'prc_target_health.target_id' => 'prc_source_targets/id/P2.1/NO ACTION',
        'prc_adapter_health.platform_id' => 'prc_platforms/id/P2.1/NO ACTION',
        'prc_detail_snapshots.procurement_id' => 'prc_procurements/id/P2.14/NO ACTION',
        'prc_status_refreshes.procurement_id' => 'prc_procurements/id/P2.14/NO ACTION',
        'prc_status_refreshes.intent_id' => 'prc_operation_intents/id/P2.14/NO ACTION',
        'prc_procurement_documents.procurement_id' => 'prc_procurements/id/P2.14/NO ACTION',
        'prc_procurement_documents.document_id' => 'prc_documents/id/P2.14/NO ACTION',
        'prc_storage_reservations.domain_id' => 'prc_storage_domains/id/P3.1/NO ACTION',
        'prc_storage_reservations.work_id' => 'prc_process_work/id/P3.1/NO ACTION',
        'prc_storage_reservations.attempt_id' => 'prc_process_attempts/id/P3.1/NO ACTION',
        'prc_storage_reservations.operation_id' => 'prc_outbound_operations/id/P3.1/NO ACTION',
        'prc_document_blobs.storage_domain_id' => 'prc_storage_domains/id/P3.1/NO ACTION',
        'prc_analysis_runs.document_version_id' => 'prc_document_versions/id/P3.8/NO ACTION',
        'prc_analysis_runs.representation_id' => 'prc_document_representations/id/P3.8/NO ACTION',
        'prc_analysis_runs.supersedes_run_id' => 'prc_analysis_runs/id/P3.8/NO ACTION',
        'prc_analysis_windows.run_id' => 'prc_analysis_runs/id/P3.8/NO ACTION',
        'prc_analysis_chunks.run_id' => 'prc_analysis_runs/id/P3.8/NO ACTION',
        'prc_analysis_chunks.window_id' => 'prc_analysis_windows/id/P3.8/NO ACTION',
        'llm_provider_requests.replay_of' => 'llm_provider_requests/logical_request_id/P3.9/NO ACTION',
        'llm_budget_reservations.operation_id' => 'llm_provider_calls/physical_call_id/P3.9/NO ACTION',
        'llm_budget_reservations.period_id' => 'llm_budget_periods/id/P3.9/NO ACTION',
        'llm_provider_calls.logical_request_id' => 'llm_provider_requests/logical_request_id/P3.9/NO ACTION',
        'llm_provider_calls.provider_id' => 'llm_providers/id/P3.9/NO ACTION',
        'llm_provider_calls.reservation_id' => 'llm_budget_reservations/id/P3.9/NO ACTION',
        'prc_analysis_llm_requests.analysis_run_id' => 'prc_analysis_runs/id/P3.9/NO ACTION',
        'prc_analysis_llm_requests.work_id' => 'prc_process_work/id/P3.9/NO ACTION',
        'prc_analysis_llm_requests.attempt_id' => 'prc_process_attempts/id/P3.9/NO ACTION',
        'prc_analysis_llm_requests.logical_request_id' => 'llm_provider_requests/logical_request_id/P3.9/NO ACTION',
        'prc_analysis_llm_requests.physical_call_id' => 'llm_provider_calls/physical_call_id/P3.9/NO ACTION',
        'llm_file_assets.provider_id' => 'llm_providers/id/P3.12/NO ACTION',
        'llm_context_caches.provider_id' => 'llm_providers/id/P3.12/NO ACTION',
        'prc_network_route_profile_members.profile_id' => 'prc_network_route_profiles/id/P2.1/NO ACTION',
        'prc_network_route_profile_members.route_id' => 'prc_network_routes/id/P2.1/NO ACTION',
    ];

    private const array STATES = ['ready', 'admitted', 'running', 'deferred', 'blocked', 'succeeded', 'failed_item', 'cancelled', 'superseded'];

    private const array OWNERS = [
        'listing' => ['crawl_block_id', 'prc_crawl_blocks', 'uuid', 'P1.6'],
        'detail' => ['procurement_id', 'prc_procurements', 'bigint', 'P1.6'],
        'status_refresh' => ['procurement_id', 'prc_procurements', 'bigint', 'P1.6'],
        'download' => ['document_id', 'prc_documents', 'bigint', 'P1.6'],
        'prepare' => ['document_version_id', 'prc_document_versions', 'bigint', 'P3.1'],
        'screening' => ['document_version_id', 'prc_document_versions', 'bigint', 'P3.1'],
        'extraction' => ['analysis_window_id', 'prc_analysis_windows', 'uuid', 'P3.8'],
        'publication' => ['analysis_run_id', 'prc_analysis_runs', 'uuid', 'P3.8'],
    ];

    private const array CARRIERS = [
        'prc_control_scopes', 'prc_process_lanes', 'prc_process_runs', 'prc_process_incidents',
        'prc_process_handler_holds', 'prc_crawl_runs', 'prc_crawl_blocks', 'prc_operation_intents',
        'prc_process_work', 'prc_process_attempts', 'prc_admission_groups', 'prc_process_reservations',
        'prc_process_run_work', 'prc_process_transitions', 'prc_process_outbox', 'prc_process_daemon_heartbeats',
        'prc_request_attempts', 'prc_observations', 'prc_observation_applications',
        'prc_process_projection_states', 'prc_process_metric_totals', 'prc_process_projection_receipts',
        'prc_network_routes', 'prc_network_route_profiles', 'prc_network_route_profile_members',
        'prc_source_targets', 'prc_route_health', 'prc_route_target_health', 'prc_target_health', 'prc_adapter_health',
        'prc_outbound_operations', 'prc_outbound_operation_controls', 'prc_network_budgets',
        'prc_network_demands', 'prc_network_permits', 'prc_network_permit_scopes',
        'prc_detail_snapshots', 'prc_status_refreshes', 'prc_procurement_documents',
        'prc_storage_domains', 'prc_storage_reservations', 'prc_document_blobs',
        'prc_analysis_runs', 'prc_analysis_windows', 'prc_analysis_chunks', 'prc_analysis_llm_requests',
        'llm_provider_requests', 'llm_provider_calls', 'llm_budget_periods', 'llm_budget_reservations',
        'llm_budget_accounts', 'llm_financial_actions',
        'llm_file_assets', 'llm_context_caches',
    ];

    /** @return list<string> */
    public static function violations(string $root, array $inventory, string $document, ?array $actual = null): array
    {
        $errors = [];
        $require = static function (bool $ok, string $message) use (&$errors): void {
            if (! $ok) {
                $errors[] = $message;
            }
        };
        $require(($inventory['format'] ?? null) === 'process-schema-inventory/v1', 'unknown inventory format');
        $require(self::order((string) ($inventory['current_item'] ?? '')) !== PHP_INT_MAX, 'unknown current_item');
        $require(($inventory['conventions']['work_states'] ?? []) === self::STATES, 'WorkState must contain exactly the nine canonical values');
        $require(($inventory['conventions']['stages'] ?? []) === array_keys(self::OWNERS), 'StageCode must contain the eight canonical values');
        $require(($inventory['conventions']['cascade_exceptions'] ?? []) === ['prc_process_reservations.admission_group_id', 'prc_network_permit_scopes.permit_id'], 'CASCADE exceptions must match D27');

        $baseline = $inventory['baseline']['tables'] ?? [];
        $carriers = $inventory['carriers'] ?? [];
        $require(array_diff(self::CARRIERS, array_keys($carriers)) === [], 'missing target carriers: '.implode(', ', array_diff(self::CARRIERS, array_keys($carriers))));
        $require($baseline !== [], 'empty baseline inventory');
        $migrations = $inventory['migrations'] ?? [];
        $paths = array_column($migrations, 'path');
        $actualPaths = array_map(static fn (string $p): string => substr($p, strlen($root) + 1), glob($root.'/database/migrations/*.php'));
        sort($paths);
        sort($actualPaths);
        $require($paths === $actualPaths && count(array_unique($paths)) === count($paths), 'migration inventory differs from the complete source set');

        foreach ($migrations as $migration) {
            $path = $migration['path'];
            $require(is_file($root.'/'.$path) && hash_file('sha256', $root.'/'.$path) === ($migration['sha256'] ?? null), 'migration input drift: '.$path);
            $require(! empty($migration['action']) && ! empty($migration['owner_item']) && ! empty($migration['reason']), 'migration disposition/owner missing: '.$path);
        }

        foreach ($baseline as $name => $table) {
            $require(! empty($table['owner']) && isset($inventory['retention'][$table['retention'] ?? '']), 'baseline owner/retention missing: '.$name);
            $require(! empty($table['columns']) && ! empty($table['definition']), 'baseline column/DDL inventory missing: '.$name);
            $require(count(array_unique(array_column($table['columns'], 'column_name'))) === count($table['columns']), 'duplicate baseline column: '.$name);

            foreach ($table['columns'] as $column) {
                $require(! empty($column['data_type']) && in_array($column['is_nullable'] ?? null, ['YES', 'NO'], strict: true), 'baseline type/nullability missing: '.$name.'.'.$column['column_name']);
                $require(preg_match('/^\s*"?'.preg_quote($column['column_name'], '/').'"?\s+/m', $table['definition']) === 1, 'baseline column absent from DDL: '.$name.'.'.$column['column_name']);
            }
        }

        foreach ($carriers as $name => $table) {
            $require(! empty($table['owner']) && isset($inventory['retention'][$table['retention'] ?? '']), 'carrier owner/retention missing: '.$name);
            $require(isset($table['create_at'], $table['activate_at']) && self::order($table['create_at']) <= self::order($table['activate_at']), 'carrier creation/activation missing or inverted: '.$name);
            $require(! empty($table['columns']) && array_key_exists('constraints', $table) && array_key_exists('indexes', $table), 'carrier schema incomplete: '.$name);

            $installed = self::order($table['create_at'] ?? '') <= self::order($inventory['current_item'] ?? '');
            if (! $installed) {
                $require(! isset($baseline[$name]), 'future carrier activated in '.($inventory['current_item'] ?? '?').' baseline: '.$name);
            } elseif (! isset($baseline[$name])) {
                $require(false, 'created carrier missing from installed baseline: '.$name);
            } else {
                self::checkInstalledCarrier($name, $table, $baseline[$name], $require);
            }

            foreach ($table['columns'] ?? [] as $column => $spec) {
                $require(! empty($spec['type']) && is_bool($spec['nullable'] ?? null) && ! empty($spec['definition']), 'column type/nullability missing: '.$name.'.'.$column);
                $require(str_starts_with($spec['definition'] ?? '', $column.' '.$spec['type']), 'column declaration/type mismatch: '.$name.'.'.$column);
                $require(($spec['nullable'] ?? null) === (preg_match('/\bNOT NULL\b|\bPRIMARY KEY\b/', $spec['definition']) !== 1), 'column declaration/nullability mismatch: '.$name.'.'.$column);
                self::checkReference($name.'.'.$column, $spec['references'] ?? null, $baseline, $carriers, $require);
            }
        }

        foreach (self::REQUIRED_COLUMNS as $name => $names) {
            $missing = array_diff(explode(' ', $names), array_keys($carriers[$name]['columns'] ?? []));
            $require($missing === [], 'missing target columns: '.$name.'.'.implode(',', $missing));
        }
        foreach (self::REQUIRED_FKS as $label => $expected) {
            [$name, $column] = explode('.', $label, 2);
            $ref = $carriers[$name]['columns'][$column]['references'] ?? null;
            $value = $ref === null ? '' : implode('/', [$ref['table'], implode(',', $ref['columns']), $ref['at'], $ref['on_delete']]);
            $require($value === $expected, 'target FK contract differs: '.$label);
        }

        $work = $carriers['prc_process_work'] ?? [];
        $require(self::enumValues($work['columns']['state']['definition'] ?? '') === self::STATES, 'work state CHECK differs from nine canonical values');
        foreach (['prc_process_work', 'prc_operation_intents', 'prc_process_reservations', 'prc_process_handler_holds'] as $name) {
            $require(self::enumValues($carriers[$name]['columns']['stage']['definition'] ?? '') === ['listing', 'detail', 'status_refresh', 'download', 'prepare', 'screening'], 'current stage CHECK differs from SchemaSupportedStages: '.$name);
        }
        foreach (['provider_recovery_due_check', 'owner_check', 'checkpoint_schema_check', 'running_lease_check', 'admitted_claim_check', 'due_state_check'] as $rule) {
            $require(str_contains(implode(' ', $work['constraints'] ?? []), 'prc_process_work_'.$rule), 'missing work CHECK: '.$rule);
        }
        foreach (['due_idx', 'lease_idx', 'claim_idx', 'provider_recovery_due_idx', 'fairness_idx'] as $index) {
            $require(str_contains(implode(' ', $work['indexes'] ?? []), 'prc_process_work_'.$index), 'missing work index: '.$index);
        }

        foreach (self::OWNERS as $stage => [$column, $table, $type, $at]) {
            $subject = $inventory['subjects'][$stage] ?? [];
            $spec = $work['columns'][$column] ?? [];
            $require(($subject['column'] ?? null) === $column && ($subject['table'] ?? null) === $table && ($subject['type'] ?? null) === $type && ($subject['fk_at'] ?? null) === $at, 'stage owner tuple differs: '.$stage);
            $require(($spec['type'] ?? null) === $type && ($spec['references']['table'] ?? null) === $table && ($spec['references']['at'] ?? null) === $at, 'concrete work owner FK missing: '.$stage);
            $expectedAncestors = match ($stage) {
                'prepare', 'screening' => ['prc_documents', 'prc_document_versions'],
                'extraction' => ['prc_documents', 'prc_document_versions', 'prc_analysis_runs', 'prc_analysis_windows'],
                'publication' => ['prc_documents', 'prc_document_versions', 'prc_analysis_runs'],
                default => [$table],
            };
            $require(($subject['ancestors'] ?? []) === $expectedAncestors && ! empty($subject['fence']), 'incomplete subject ancestors/fence: '.$stage);
        }

        foreach ($inventory['staging'] ?? [] as $row) {
            $require(array_diff([...$row['active'], ...$row['fixture_only']], $row['supported']) === [], 'activation of unsupported stage: '.$row['at']);
            $require(array_intersect($row['active'], $row['fixture_only']) === [], 'fixture-only stage is active: '.$row['at']);
            $expected = array_keys(array_filter(self::OWNERS, static fn (array $owner): bool => self::order($owner[3]) <= self::order($row['at'])));
            $require($row['supported'] === $expected, 'schema-supported stage set differs from FK staging: '.$row['at']);
            foreach ($row['active'] as $stage) {
                $activation = match ($stage) {
                    'listing', 'detail', 'status_refresh' => 'P2.18',
                    'download', 'prepare' => 'P3.5',
                    default => 'P3.13',
                };
                $require(self::order($row['at']) >= self::order($activation), 'real handler precedes owning cutover: '.$stage.' @'.$row['at']);
            }
        }
        $require(array_column($inventory['staging'] ?? [], 'at') === ['P1.1', 'P1.6', 'P2.18', 'P3.1', 'P3.5', 'P3.8', 'P3.13'], 'incomplete stage activation map');

        foreach ($inventory['changes'] ?? [] as $change) {
            $require(isset($baseline[$change['table']]) && ! empty($change['at']), 'change has no real baseline owner: '.$change['table']);
            if (isset($baseline[$change['table']]) && self::order($change['at']) <= self::order($inventory['current_item'] ?? '')) {
                $names = array_column($baseline[$change['table']]['columns'], 'column_name');
                foreach (array_keys($change['columns']) as $column) {
                    $require(in_array($column, $names, strict: true), 'installed change column missing from baseline: '.$change['table'].'.'.$column);
                }
            }
            foreach ($change['columns'] as $column => $spec) {
                self::checkReference($change['table'].'.'.$column, $spec['references'] ?? null, $baseline, $carriers, $require);
            }
        }

        foreach (['prc_source_circuits' => 'P2.18', 'prc_source_incidents' => 'P3.17', 'prc_ai_analysis_runs' => 'P3.13', 'llm_calls' => 'P3.13'] as $table => $at) {
            $mustExist = self::order((string) ($inventory['current_item'] ?? '')) < self::order($at);
            $require(isset($baseline[$table]) === $mustExist && ($inventory['legacy'][$table]['drop_at'] ?? null) === $at, 'legacy reader/table cutover differs: '.$table);
        }

        if ($errors === []) {
            $generated = self::render($inventory);
            $require(str_contains($document, $generated), 'docs/schema manifest mismatch: regenerate the complete inventory block');
        }
        if ($actual !== null) {
            self::checkCatalog($baseline, $actual, $require);
        }

        return $errors;
    }

    private static function order(string $item): int
    {
        return preg_match('/^P([123])\.(\d+)$/', $item, $m) === 1 ? (int) $m[1] * 100 + (int) $m[2] : PHP_INT_MAX;
    }

    /** An installed carrier keeps its declared column order, named constraints and indexes in the real catalog. */
    private static function checkInstalledCarrier(string $name, array $carrier, array $installed, callable $require): void
    {
        $require(array_column($installed['columns'], 'column_name') === array_keys($carrier['columns']), 'installed carrier columns differ from declaration: '.$name);
        preg_match_all('/\bCONSTRAINT\s+(\w+)/', implode("\n", $carrier['constraints']), $constraints);
        $actualConstraints = array_column($installed['constraints'], 'name');
        foreach ($constraints[1] as $constraint) {
            $require(in_array($constraint, $actualConstraints, strict: true), 'installed carrier constraint missing: '.$name.'.'.$constraint);
        }
        preg_match_all('/\bINDEX\s+(\w+)/', implode("\n", $carrier['indexes']), $indexes);
        $actualIndexes = array_column($installed['indexes'], 'indexname');
        foreach ($indexes[1] as $index) {
            $require(in_array($index, $actualIndexes, strict: true), 'installed carrier index missing: '.$name.'.'.$index);
        }
    }

    private static function enumValues(string $definition): array
    {
        preg_match('/\bIN\s*\(([^)]+)\)/', $definition, $match);
        preg_match_all("/'([^']+)'/", $match[1] ?? '', $values);

        return $values[1];
    }

    private static function checkReference(string $label, ?array $ref, array $baseline, array $carriers, callable $require): void
    {
        if ($ref === null) {
            return;
        }
        $target = $ref['table'];
        $require(isset($baseline[$target]) || isset($carriers[$target]), 'placeholder FK target: '.$label.' -> '.$target);
        if (isset($carriers[$target])) {
            $require(self::order($carriers[$target]['create_at'] ?? '') <= self::order($ref['at']), 'FK precedes real target creation: '.$label);
        }
        foreach ($ref['columns'] as $column) {
            $names = isset($baseline[$target]) ? array_column($baseline[$target]['columns'], 'column_name') : array_keys($carriers[$target]['columns'] ?? []);
            $require(in_array($column, $names, strict: true), 'FK target column missing: '.$label.' -> '.$target.'.'.$column);
        }
        if ($ref['on_delete'] === 'CASCADE') {
            $require(in_array($label, ['prc_process_reservations.admission_group_id', 'prc_network_permit_scopes.permit_id'], strict: true), 'unauthorized CASCADE: '.$label);
        }
        if (in_array($label, ['prc_process_reservations.admission_group_id', 'prc_network_permit_scopes.permit_id'], strict: true)) {
            $require($ref['on_delete'] === 'CASCADE', 'composition-child CASCADE missing: '.$label);
        }
    }

    private static function checkCatalog(array $baseline, array $actual, callable $require): void
    {
        $catalog = $actual['catalog'];
        $children = array_map(static fn (array $p): string => preg_replace('/^public\./', '', $p['child']), $catalog['partitions']);
        preg_match_all('/CREATE TABLE public\.(\w+) \(\n[\s\S]*?\n\)[^;]*;/', $actual['schema'], $matches, PREG_SET_ORDER);
        $definitions = [];
        foreach ($matches as $match) {
            if (! in_array($match[1], $children, strict: true)) {
                $definitions[$match[1]] = $match[0];
            }
        }
        $expectedNames = array_keys($baseline);
        $actualNames = array_keys($definitions);
        sort($expectedNames);
        sort($actualNames);
        $require($expectedNames === $actualNames, 'real catalog root table set differs from inventory');
        foreach ($baseline as $name => $table) {
            $require(($definitions[$name] ?? null) === $table['definition'], 'real column/type/nullability/CHECK DDL differs: '.$name);
            $constraints = array_values(array_filter($catalog['constraints'], static fn (array $r): bool => $r['schema'] === 'public' && $r['table_name'] === $name));
            $indexes = array_values(array_filter($catalog['indexes'], static fn (array $r): bool => $r['schemaname'] === 'public' && $r['tablename'] === $name));
            $columns = array_values(array_filter($catalog['columns'], static fn (array $r): bool => $r['table_schema'] === 'public' && $r['table_name'] === $name));
            $require(self::canonicalizeMaps($columns) === self::canonicalizeMaps($table['columns']), 'real column inventory differs: '.$name);
            $require(self::canonicalizeMaps($constraints) === self::canonicalizeMaps($table['constraints']), 'real FK/CHECK/UNIQUE inventory differs: '.$name);
            $require(self::canonicalizeMaps($indexes) === self::canonicalizeMaps($table['indexes']), 'real index inventory differs: '.$name);
        }
    }

    /** Ignore map insertion order while retaining strict scalar types and list order. */
    private static function canonicalizeMaps(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeMaps($item);
        }

        return $value;
    }

    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', preg_replace('/\s+/', ' ', $text));
    }

    /** A deterministic full document, not a headings-only or file-existence check. */
    public static function render(array $inventory): string
    {
        $lines = ['<!-- schema-inventory:begin -->', '## Versioned inventory', '',
            'Source: `tests/Support/ProcessCore/schema-inventory.json` (`process-schema-inventory/v1`).', '',
            '### Retention', '', '| Policy | Contract |', '| --- | --- |'];
        foreach ($inventory['retention'] as $key => $text) {
            $lines[] = '| '.$key.' | '.self::cell($text).' |';
        }
        $lines = [...$lines, '', '### Schema support and handler activation', '', '| Item | SchemaSupportedStages | Active real handlers | Fixture-only (testing) |', '| --- | --- | --- | --- |'];
        foreach ($inventory['staging'] as $row) {
            $lines[] = '| '.$row['at'].' | '.implode(', ', $row['supported']).' | '.implode(', ', $row['active']).' | '.implode(', ', $row['fixture_only']).' |';
        }
        $lines = [...$lines, '', '### Concrete subjects and ancestors', '', '| Stage | Owner FK / type | FK installed | Full subject set | Fence |', '| --- | --- | --- | --- | --- |'];
        foreach ($inventory['subjects'] as $stage => $s) {
            $lines[] = '| '.$stage.' | '.$s['column'].' → '.$s['table'].'.id / '.$s['type'].' | '.$s['fk_at'].' | '.implode(', ', $s['ancestors']).' | '.self::cell($s['fence']).' |';
        }
        $lines = [...$lines, '', '### Migration ownership and disposition', '', '| Migration | Disposition | Owner | Rule |', '| --- | --- | --- | --- |'];
        foreach ($inventory['migrations'] as $m) {
            $lines[] = '| `'.basename($m['path']).'` | '.$m['action'].' | '.$m['owner_item'].' | '.self::cell($m['reason']).' |';
        }
        $lines = [...$lines, '', '### Legacy lifecycle', '', '| Table | Writer cutover | Last reader/table removal | Replacement |', '| --- | --- | --- | --- |'];
        foreach ($inventory['legacy'] as $name => $t) {
            $lines[] = '| '.$name.' | '.$t['writer_cutover'].' | '.$t['drop_at'].' | '.self::cell($t['replacement']).' |';
        }
        $lines = [...$lines, '', '### Future carriers', ''];
        foreach ($inventory['carriers'] as $name => $t) {
            $lines = [...$lines, '#### '.$name, '', 'Create: '.$t['create_at'].'; activation boundary: '.$t['activate_at'].'; owner: `'.$t['owner'].'`; retention: '.$t['retention'].'. Contract: '.$t['contract'].'.', '', '| Column | Type | Nullable | Declaration / FK installed |', '| --- | --- | --- | --- |'];
            foreach ($t['columns'] as $name => $c) {
                $ref = isset($c['references']) ? ' → '.$c['references']['table'].'('.implode(',', $c['references']['columns']).') ON DELETE '.$c['references']['on_delete'].' @'.$c['references']['at'] : '';
                $lines[] = '| '.$name.' | '.$c['type'].' | '.($c['nullable'] ? 'yes' : 'no').' | '.self::cell($c['definition'].$ref).' |';
            }
            $lines = [...$lines, '', 'Constraints:'];
            foreach ($t['constraints'] as $text) {
                $lines[] = '- `'.self::cell($text).'`';
            }
            $lines = [...$lines, '', 'Indexes:'];
            foreach ($t['indexes'] as $text) {
                $lines[] = '- `'.self::cell($text).'`';
            }
            $lines[] = '';
        }
        $lines = [...$lines, '### Changes to retained baseline tables', ''];
        foreach ($inventory['changes'] as $t) {
            $lines = [...$lines, '#### '.$t['table'].' @'.$t['at'], '', self::cell($t['note']), '', '| Column | Type | Nullable | Target declaration |', '| --- | --- | --- | --- |'];
            foreach ($t['columns'] as $name => $c) {
                $lines[] = '| '.$name.' | '.$c['type'].' | '.($c['nullable'] ? 'yes' : 'no').' | '.self::cell($c['definition']).' |';
            }
            $lines = [...$lines, '', 'Remove at this cutover: '.(implode(', ', $t['drop_columns']) ?: 'none').'.', '', 'Constraints:'];
            foreach ($t['constraints'] as $text) {
                $lines[] = '- `'.self::cell($text).'`';
            }
            $lines[] = '';
        }
        $lines = [...$lines, '### Installed baseline (before Process ledger)', ''];
        foreach ($inventory['baseline']['tables'] as $name => $t) {
            $lines = [...$lines, '#### '.$name, '', 'Owner: '.$t['owner'].'; retention: '.$t['retention'].'.', '', '| Column | PostgreSQL type | Nullable | Exact column definition |', '| --- | --- | --- | --- |'];
            foreach ($t['columns'] as $c) {
                preg_match('/^\s*"?'.preg_quote($c['column_name'], '/').'"?\s+(.+)$/m', $t['definition'], $m);
                $lines[] = '| '.$c['column_name'].' | '.$c['data_type'].' / '.$c['udt_name'].' | '.$c['is_nullable'].' | '.self::cell(rtrim($m[1] ?? '', ',')).' |';
            }
            $lines = [...$lines, '', 'Constraints:'];
            foreach ($t['constraints'] as $c) {
                $lines[] = '- `'.$c['name'].'`: `'.self::cell($c['definition']).'`';
            }
            $lines = [...$lines, '', 'Indexes:'];
            foreach ($t['indexes'] as $i) {
                $lines[] = '- `'.$i['indexname'].'`: `'.self::cell($i['indexdef']).'`';
            }
            $lines[] = '';
        }
        $lines[] = '<!-- schema-inventory:end -->';

        return implode("\n", $lines)."\n";
    }
}
