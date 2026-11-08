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
- **Source hash & architecture sync**:
  - If editing a file tracked in `tests/Fixtures/ProcessCore/manifest.json` (`source_classes`, `migrations`, `contract_fixtures`, `files`), update its SHA-256 in `manifest.json` (and `tests/Support/ProcessCore/schema-inventory.json` for migrations) before running `make core-quality` / `make core-test-regression`.
  - If editing prompts or gold fixtures in `tests/Fixtures/ProcessCore/runtime-inputs.json`, sync their SHA-256 there.
  - If adding/removing classes under `App\{Enums,Exceptions,Dto,Repositories}\Procurement` or core namespaces, check `DomainLayoutTest.php` and `CoreKnowsNoDocumentTypeTest.php`.
- **CLI diagnostics**: `php artisan process:status --platform=eis`, `php artisan process:diagnose <uuid>`, `php artisan process:control {pause|resume|cancel-run|cancel-work|retry-work|retry-failed-item|reconcile-unknown}`.

## 5. Local Pipeline Rehearsal & Stage Gating (No Paid AI)

- **Provisioning without LLM spend**: seed base catalogs (`BaseSeeder`, `EisCrawlerProxySeeder`, `EisAuditSeeder`), then run `php artisan platform:sync-catalog` and `php artisan process:sync-documents`. **Do not** run `php artisan process:sync-analysis` locally (leaves `screening`/`extraction`/`publication` handler holds unregistered and `llm_budget_periods` closed). Legacy `.env` flags (`AI_SCREENING_ENABLED`, `CRAWLER_DOWNSTREAM_ENABLED`) do not gate Process Core stages.
- **VDS proxy & VPN bypass**: `EisCrawlerProxySeeder` binds `PLAYWRIGHT_MCP_PROXY_SERVER` to route profiles `eis.audit` and `default` (routing `listing`, `detail`, `status_refresh`, and `download`). When host VPN (`amn0`) is active, route the VDS IP directly via LAN gateway (`sudo ip route replace <VDS_IP>/32 via <LAN_GW> dev <LAN_IF>`).
- **Iterative stage-by-stage verification** (log each iteration in `/Audits/`):
  1. **Listing only**: pause `acquisition:stage:detail` and `documents` via `php artisan process:control pause <scope> --user=<id> --reason="..." --expected=<ver>`. Run a bounded window (`php artisan process:run <profile_id> --kind=archive --from=YYYY-MM-DD --to=YYYY-MM-DD`), wait for 2-pass block completion (`prc_crawl_blocks.status = complete`), and reconcile `COUNT(*)` in `prc_procurements` against `zakupki.gov.ru` via Playwright using identical filters.
  2. **Detail cards**: `process:control resume acquisition:stage:detail ...` (keep `documents` paused or pause `documents:stage:prepare` / `documents:stage:screening`). Verify 4 card tabs + control pass, `prc_revisions`, `prc_procurement_parties`, `prc_events`, and `prc_documents` / `prc_document_versions`.
  3. **Change tracking (`fresh` / `delta` & `status_refresh`)**: test `AcquisitionKind::Delta` (`sortBy = UPDATE_DATE`) and `php artisan process:schedule-acquisition` (`next_status_due_at <= now()`); verify `prc_procurements.head_generation` increments and updated cards/documents are re-ingested. Full runbook: `docs/dev/runbooks/archive-run.md`.
