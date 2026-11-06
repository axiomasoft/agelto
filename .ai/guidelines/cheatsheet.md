# Agelto — Schema, Lock-Order & Pipeline Cheat-Sheet

## 1. Canonical Lock Order (Deadlock Prevention)

- **Process subject prefix** (`SubjectLockResolver`): lock `prc_control_scopes` (`ORDER BY scope_key`), then subject rows in fixed stage prefix order (`prc_crawl_runs` → `prc_crawl_blocks` / `prc_procurements` → `prc_documents` → `prc_document_versions` → `prc_analysis_runs` → `prc_analysis_windows`), then `prc_operation_intents`, then `prc_process_work` / `prc_process_attempts`.
- **LLM ledger lock order** (`RequestRepository` / `FinancialReservationRepository` / `AssetRepository`):
  `llm_budget_accounts` → `llm_budget_periods` → `llm_provider_requests` → `llm_provider_calls` → `llm_file_assets` / `llm_context_caches`.
- **Rule**: Never perform object storage I/O (`Storage::disk(...)`) or HTTP calls inside an open DB transaction (`DB::transactionLevel() === 0` is enforced by `ReceiptStore` and `AnalysisProviderSendGate`). Always use PostgreSQL `clock_timestamp()` for leases, permits, and deadlines.

## 2. Process Stages & Owner Subjects (`prc_process_work`)

| Stage (`StageCode`) | Owner Subject (`SubjectKind`) | Work FK Column | Queue (`connection:queue`) | Chain (`slice < job < horizon < retry_after < lease`) |
|---|---|---|---|---|
| `listing` | `prc_crawl_blocks` | `crawl_block_id` | `process_long:process-listing` | `230 < 260 < 280 < 330 < 360` |
| `detail` | `prc_procurements` | `procurement_id` | `process_short:process-acquisition` | `90 < 110 < 120 < 150 < 180` |
| `status_refresh` | `prc_procurements` | `procurement_id` | `process_short:process-acquisition` | `90 < 110 < 120 < 150 < 180` |
| `download` | `prc_documents` | `document_id` | `process_download:process-download` | `290 < 320 < 340 < 390 < 420` |
| `prepare` | `prc_document_versions` | `document_version_id` | `process_long:process-prepare` | `230 < 260 < 280 < 330 < 360` |
| `screening` | `prc_document_versions` | `document_version_id` | `process_ai:process-ai` | `260 < 290 < 310 < 360 < 390` |
| `extraction` | `prc_analysis_windows` | `analysis_window_id` | `process_ai:process-ai` | `260 < 290 < 310 < 360 < 390` |
| `publication` | `prc_analysis_runs` | `analysis_run_id` | `process_short:process-publication` | `90 < 110 < 120 < 150 < 180` |

## 3. Core Tables by Domain

- **Process (`prc_process_*`, `prc_operation_intents`)**: `prc_process_runs`, `prc_operation_intents` (at most 1 `active` + 1 `pending` per `(subject_kind, subject_id, stage)`), `prc_process_work`, `prc_process_attempts` (lease + `owner_token`), `prc_admission_groups`, `prc_process_reservations`, `prc_process_outbox` (`work_wake`, `feeder_wake`, `projection`), `prc_process_transitions` (append-only audit), `prc_process_incidents`, `prc_control_scopes`, `prc_process_daemon_heartbeats`.
- **Network (`prc_network_*`, `prc_source_targets`, `prc_*_health`)**: `prc_source_targets`, `prc_network_routes`, `prc_network_route_profiles`, `prc_network_demands`, `prc_network_permits` (`reserved` → `sent` → `completed`/`expired`), `prc_request_attempts`, `prc_route_health`, `prc_target_health`, `prc_route_target_health`, `prc_adapter_health`.
- **Acquisition & Documents (`prc_*`)**: `prc_crawl_runs`, `prc_crawl_blocks` (`terminal_proof`), `prc_procurements` (`head_generation`), `prc_revisions`, `prc_events`, `prc_documents` (`head_generation`, `current_version_id`), `prc_document_versions` (`preparation_status`, `screening_status`, `published_analysis_run_id`), `prc_document_blobs`, `prc_document_representations`, `prc_storage_reservations`.
- **AI & LLM Ledger (`prc_analysis_*`, `prc_estimate*`, `llm_*`)**: `prc_analysis_runs`, `prc_analysis_windows`, `prc_analysis_chunks` (immutable), `prc_analysis_llm_requests`, `prc_estimates` / `prc_estimate_items` / `prc_estimate_sections` / `prc_estimate_resources` / `prc_estimate_totals`, `llm_budget_accounts`, `llm_budget_periods`, `llm_provider_requests`, `llm_provider_calls` (sealed wire fingerprint + immutable receipt), `llm_budget_reservations`, `llm_financial_actions`, `llm_file_assets`, `llm_context_caches`.
- **Organization & Reference (`org_*`, `ref_*`)**: `org_organizations`, `org_organization_identifiers` (`platform_code`, `type`, `value`), `prc_procurement_parties`, `ref_okpd2`, `ref_ktru`, `ref_okved2`, `ref_kbk`.

## 4. Fast Verification & Diagnostic Tricks

- **Isolated rig lifecycle**: `make core-test-up` → `make core-quality` → `make core-test-file TEST_FILE=tests/Feature/...` or suite targets (`make core-test-execution`, `core-test-concurrency`, `core-test-network`, `core-test-platforms`, `core-test-acquisition`, `core-test-documents`, `core-test-ai`, `core-test-observability`, `core-test-install`, `core-test-regression`).
- **Source hash sync**: if editing a file tracked in `tests/Fixtures/ProcessCore/manifest.json['source_classes']`, update its SHA-256 in `manifest.json` before running `make core-quality` / `make core-test-regression`.
- **CLI diagnostics**: `php artisan process:status --platform=eis`, `php artisan process:diagnose <uuid>`, `php artisan process:control {pause|resume|cancel-run|cancel-work|retry-work|retry-failed-item|reconcile-unknown}`.
