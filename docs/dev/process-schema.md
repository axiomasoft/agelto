---
audience: dev
type: reference
status: living
---

# Process schema: baseline, ownership и staged target

P1.1 фиксирует полный inventory; он не создаёт Process tables, handlers, очереди или runtime flags.
Исходный baseline сохраняется: 81 project migration, 75 root tables, 939 columns. Partition children
наследуют contract родителя и остаются в полном install catalog, а не дублируются в таблицах ниже.
Vendor/framework tables включены с собственным владельцем; extension-owned views не являются app tables.

Owning source — tests/Support/ProcessCore/schema-inventory.json. Документ содержит все его таблицы,
колонки, types/nullability/defaults, реальные FK, CHECK/UNIQUE/index, owners и retention. Изменение
исходника требует обновить полный блок через php tests/Support/ProcessCore/verify-schema.php --render;
проверка сравнивает весь блок, а не заголовки. Нормативные core/permit declarations взяты из
execution#s04 и implementation-blueprint#bp-ddl-core/#bp-ddl-network; domain carriers — из
platform-transport#s06, documents-ai#s01/#s04–s10 и observability#s08. Это inventory для будущих
owning baseline edits, не команда исполнять приведённый DDL раньше cutover.

Baseline provenance — сохранённый P4.3 install catalog 20261024T090626.584392Z-1727817. Hashes всех
142 migration/model/partition-config inputs сверены с P1.1 worktree до capture. Исходные candidate,
raw catalog/schema SHA-256 и source hashes сохранены в inventory. Этот provenance не является
P1.1 clean-install pass. Каждый core-test-install по-прежнему полностью пересоздаёт только
agelto_test дважды, включая partman schema, и сравнивает schema/seed/extension/partman catalogs.
Новый read-only guard дополнительно сопоставляет каждый реальный root table, колонку, тип,
nullability, FK/CHECK/UNIQUE/index с inventory после каждого reset. Миграции и DB не запускаются
документом или guard.

D27: WorkState содержит ровно ready/admitted/running/deferred/blocked/succeeded/failed_item/cancelled/
superseded. Partial — outcome, а recovered/stale/duplicate_delivery — события. CASCADE разрешён
только prc_process_reservations.admission_group_id и prc_network_permit_scopes.permit_id; budget,
demand, attempt, work, request/history FK сохраняют RESTRICT/NO ACTION. Существующие business FK
ниже показаны как есть; их прежний CASCADE не означает разрешения CASCADE в новом ledger.

StageCode содержит все восемь стадий. SchemaSupportedStages — отдельный supported subset с
конкретным owner FK, HandlerActivation — отдельное разрешение real/fixture handler. До P1.6
subset пуст; P1.6 не получает version/analysis owner tuples. Внутри canonical core CREATE уже
описаны nullable future owner columns; их FK добавляет только владелец реальных subjects. Эти
NULL columns не разрешают unsupported work. При расширении owning item синхронно обновляет
stage CHECK work/intents/reservations/handler_holds, owner tuple CHECK, manifest и registry.
До появления app registry здесь проверяется документный contract; boot/DB CHECK↔registry assertions
вводятся владельцами типов и ledger. Наличие FK никогда не активирует платный handler.

SubjectLockSnapshot загружается explicit SELECT/JOIN до transaction. Полный subject set берётся
FOR UPDATE и сортируется по table key и actual PK (существующие bigint, новые UUID), после чего
повторно проверяются immutable links, heads, recipe и analysis generation. Нельзя достраивать
ancestors из bare WorkItem или брать более ранний lock после work. Общий prefix:
control SHARE → handler eligibility SHARE → sorted lanes/storage UPDATE → sorted subjects UPDATE →
work UPDATE → financial/request authorities. Heartbeat — единственный work-only CAS. Финальный
DB-only commit допускает истёкший, ещё не отозванный lease; новый send/checkpoint/heartbeat — нет.

Таблицы текущего baseline, не затронутые target changes/legacy lifecycle, сохраняют приведённые
поля и ограничения. Для AI P3.8/P3.9 additive carriers используют typed QueryBuilder repositories;
старые AnalysisRun/LlmCall FQCN и bindings остаются до единого P3.13 swap. prc_ai_analyses сохраняет
result/review/correction identity и downstream FK; точный surviving consumer map проверяет owning
AI package до swap. prc_source_incidents и sidebar остаются до P3.17, circuits — до P2.18.

Retention defaults — проектные, а не нормативные сроки. Cleanup не следует автоматически из
terminal run. Deletion/ref acquisition gate, generation fencing, verified cleanup и original-period
financial settlement обязательны. В release admission group нет implicit release storage/paid reserve.

Узкие проверки без DB: php tests/Support/ProcessCore/verify-schema.php и
python3 -B -m unittest discover -s docker/tests/process-core -p test_schema_inventory.py -v.
Они доказывают inventory/guard, не real concurrency или работу будущих handlers.

<!-- schema-inventory:begin -->
## Versioned inventory

Source: `tests/Support/ProcessCore/schema-inventory.json` (`process-schema-inventory/v1`).

### Retention

| Policy | Contract |
| --- | --- |
| business | Оригиналы, estimates, gold, provenance и справочники: отдельная domain policy; завершение run не разрешает удаление. |
| authority | Active work/attempt/incident, pending outbox, pinned evidence и referenced artifacts не удаляются. Terminal work_key tombstones бессрочно при unbounded rediscovery/replay. |
| journal | Transitions/admin audit/financial metadata минимум 365 дней; только после mandatory projection receipts; source pruning атомарно помечает history incomplete. |
| requests | Successful request details 30 дней; failed/stale 90 дней; dedup identity дольше максимального callback/replay horizon. |
| incidents | Resolved incidents 180 дней; active/acknowledged incidents и pinned evidence сохраняются. |
| technical | Technical logs 14 дней; не являются authority. |
| financial | Unknown requests и их reservations не удаляются; settlement original period; financial metadata минимум 365 дней. |
| artifacts | Mark + deletion generation под lock, object delete вне tx, CAS finalize. New refs используют общий deletion gate; staged objects живого intent/lease защищены; grace >= max lease + retry horizon. |
| projection | Receipts обязательных consumers защищают source event; totals не уменьшаются от retention, active generation не удаляется; rebuild после source prune явно unavailable. |
| runtime | Кеш/queue/session/runtime TTL по framework policy; это не authority business work. |

### Schema support and handler activation

| Item | SchemaSupportedStages | Active real handlers | Fixture-only (testing) |
| --- | --- | --- | --- |
| P1.1 |  |  |  |
| P1.6 | listing, detail, status_refresh, download |  | listing, detail, status_refresh, download |
| P2.18 | listing, detail, status_refresh, download | listing, detail, status_refresh | download |
| P3.1 | listing, detail, status_refresh, download, prepare, screening | listing, detail, status_refresh | download, prepare, screening |
| P3.5 | listing, detail, status_refresh, download, prepare, screening | listing, detail, status_refresh, download, prepare | screening |
| P3.8 | listing, detail, status_refresh, download, prepare, screening, extraction, publication | listing, detail, status_refresh, download, prepare | screening, extraction, publication |
| P3.13 | listing, detail, status_refresh, download, prepare, screening, extraction, publication | listing, detail, status_refresh, download, prepare, screening, extraction, publication |  |

### Concrete subjects and ancestors

| Stage | Owner FK / type | FK installed | Full subject set | Fence |
| --- | --- | --- | --- | --- |
| listing | crawl_block_id → prc_crawl_blocks.id / uuid | P1.6 | prc_crawl_blocks | head_generation/input_revision; cursor checkpoint не увеличивает head |
| detail | procurement_id → prc_procurements.id / bigint | P1.6 | prc_procurements | head_generation/incoming revision/detail cycle |
| status_refresh | procurement_id → prc_procurements.id / bigint | P1.6 | prc_procurements | head_generation/status_cycle_seq/next_status_due_at |
| download | document_id → prc_documents.id / bigint | P1.6 | prc_documents | head_generation/current_version_id/access_descriptor_version; revalidate остаётся download intent |
| prepare | document_version_id → prc_document_versions.id / bigint | P3.1 | prc_documents, prc_document_versions | document head, immutable version, pinned preparation recipe |
| screening | document_version_id → prc_document_versions.id / bigint | P3.1 | prc_documents, prc_document_versions | document head, immutable version/representation, recipe |
| extraction | analysis_window_id → prc_analysis_windows.id / uuid | P3.8 | prc_documents, prc_document_versions, prc_analysis_runs, prc_analysis_windows | document/version/run/window links, heads, recipe, analysis generation |
| publication | analysis_run_id → prc_analysis_runs.id / uuid | P3.8 | prc_documents, prc_document_versions, prc_analysis_runs | document head + version analysis_generation/current_analysis_run_id |

### Migration ownership and disposition

| Migration | Disposition | Owner | Rule |
| --- | --- | --- | --- |
| `0001_01_01_000000_create_users_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `0001_01_01_000001_create_cache_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `0001_01_01_000002_create_jobs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100000_create_prc_platforms_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100001_create_prc_registries_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100002_create_prc_status_mappings_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100005_create_ref_okpd2_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100006_create_ref_ktru_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100007_create_ref_okved2_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100008_create_ref_kbk_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100009_create_org_organizations_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100010_create_prc_procurement_parties_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100011_create_prc_procurements_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100012_create_prc_revisions_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100013_create_prc_events_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100014_create_prc_lots_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100015_create_prc_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100016_create_prc_financing_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100017_create_prc_eis_details_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100018_create_prc_documents_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100019_create_prc_document_versions_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100020_create_prc_protocols_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100021_create_prc_bids_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100022_create_prc_awards_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100023_create_prc_clarifications_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100024_create_prc_cancellations_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100025_create_prc_ai_analyses_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100026_create_llm_calls_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100027_create_llm_providers_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100028_create_llm_routes_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100029_create_llm_prompts_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100030_create_prc_ai_dataset_examples_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100031_create_prc_ai_eval_runs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100032_create_prc_ai_eval_results_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100033_create_prc_estimates_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100034_create_prc_estimate_sections_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100035_create_prc_estimate_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100036_create_prc_estimate_resources_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100037_create_prc_estimate_totals_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100038_create_prc_crawlers_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100039_create_prc_crawler_runs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100040_create_prc_proxies_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100041_create_prc_fetches_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100042_create_prc_proxy_checks_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100044_create_prc_ignored_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100046_create_prc_operation_logs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100047_setup_pg_partman.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_07_09_000001_create_pulse_tables.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_09_100050_set_pg_partman_retention.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_07_09_100051_set_hot_partition_autovacuum.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_07_09_100052_create_prc_ai_corrections_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_27_100053_create_prc_ai_fixtures_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_27_100055_create_prc_document_representations_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_27_100057_create_prc_estimate_work_types_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_28_100058_create_prc_ai_prompt_suggestions_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_29_100059_create_prc_ai_analysis_runs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_31_100062_create_prc_proxy_targets_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_05_100068_enable_db_observability_extensions.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_08_05_100074_precreate_historical_partitions.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_08_05_100076_prune_orphaned_partman_part_config_entries.php` | remove_after_clean_proof | P1.1–P1.4 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_08_07_100078_create_prc_okpd2_nodes_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_08_100082_create_prc_crawler_proxy_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_08_100085_create_prc_platform_checks_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_09_099999_create_prc_settings_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_09_100087_replace_crawler_use_proxy_with_mode.php` | fold_into_create | P2.14 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_09_100088_add_proxy_source_to_prc_fetches.php` | delete_with_owner | P3.17 | Удалить вместе с owning legacy table и последними consumers. |
| `2026_08_09_100089_add_unprocessable_reason_to_prc_documents_table.php` | fold_into_create | P3.1 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_09_100090_add_archive_entry_path_to_prc_document_versions_table.php` | fold_into_create | P3.1 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_09_100091_migrate_screening_results_to_v2.php` | remove_after_clean_proof | P3.13 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_08_09_100092_add_applicability_to_prc_status_mappings_table.php` | fold_into_create | P2.14 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_12_100092_add_screening_summary_search_index.php` | fold_index_into_create | P3.13 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_09_10_100093_repair_partition_coverage.php` | remove_after_clean_proof | P1.1–P1.4 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_09_10_100094_add_execution_lease_to_prc_crawler_runs.php` | delete_with_owner | P2.18 | Удалить вместе с owning legacy table и последними consumers. |
| `2026_09_12_100095_preserve_2025_procurement_events.php` | remove_after_clean_proof | P1.1–P1.4 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_10_07_100096_create_prc_source_control_tables.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_10_08_100101_verify_partition_bounds.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_10_08_100102_add_source_resume_claim.php` | delete_with_owner | P2.18 | Удалить вместе с owning legacy table и последними consumers. |
| `2026_10_08_100103_store_source_time_precision.php` | fold_into_create | P2.14 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_10_08_100104_catalog_raw_artifacts.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_10_18_170000_add_eis_identity_to_org_organizations.php` | fold_into_create | Organization | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_10_23_100000_create_org_organization_identifiers_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |

### Legacy lifecycle

| Table | Writer cutover | Last reader/table removal | Replacement |
| --- | --- | --- | --- |
| prc_crawler_runs | P2.18 | P2.18 | prc_process_runs + prc_crawl_runs/prc_crawl_blocks |
| prc_crawler_proxy | P2.6 | P2.6 | prc_network_route_profiles + prc_network_route_profile_members |
| prc_proxy_targets | P2.6 | P2.6 | prc_route_target_health |
| prc_proxy_checks | P2.6 | P2.6 | prc_request_attempts + prc_observations |
| prc_source_circuits | P2.6 | P2.18 | prc_control_scopes + network health; historical read-only UI до P2.18 |
| prc_source_incidents | P1.14 | P3.17 | prc_process_incidents; legacy sidebar/read API сохранить до P3.17 |
| prc_fetches | P2.6 | P3.17 | prc_request_attempts + prc_observations |
| prc_operation_logs | P1.14 | P3.17 | prc_process_transitions + incidents |
| prc_ai_analysis_runs | P3.13 | P3.13 | prc_analysis_runs; existing AnalysisRun FQCN mapping прежний до atomic swap |
| llm_calls | P3.13 | P3.13 | llm_provider_calls; existing LlmCall FQCN mapping прежний до atomic swap |

### Future carriers

#### prc_control_scopes

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| scope_kind | text | no | scope_kind text NOT NULL CHECK (scope_kind IN ('system','acquisition','documents','platform','cohort','stage')) |
| scope_key | text | no | scope_key text NOT NULL |
| parent_id | uuid | yes | parent_id uuid NULL REFERENCES prc_control_scopes(id) → prc_control_scopes(id) ON DELETE NO ACTION @P1.6 |
| desired_mode | text | no | desired_mode text NOT NULL DEFAULT 'enabled' CHECK (desired_mode IN ('enabled','paused')) |
| control_version | bigint | no | control_version bigint NOT NULL DEFAULT 1 CHECK (control_version >= 1) |
| changed_by | bigint | yes | changed_by bigint NULL REFERENCES users(id) → users(id) ON DELETE NO ACTION @P1.6 |
| change_reason | text | yes | change_reason text NULL |
| changed_at | timestamptz | yes | changed_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_control_scopes_scope_key_unique UNIQUE (scope_key)`
- `CONSTRAINT prc_control_scopes_root_check CHECK ((scope_kind = 'system') = (parent_id IS NULL))`

Indexes:

#### prc_process_lanes

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| lane_key | text | no | lane_key text NOT NULL |
| parent_lane_id | uuid | yes | parent_lane_id uuid NULL REFERENCES prc_process_lanes(id) → prc_process_lanes(id) ON DELETE NO ACTION @P1.6 |
| max_outstanding | integer | no | max_outstanding integer NOT NULL CHECK (max_outstanding > 0) |
| max_running | integer | no | max_running integer NOT NULL CHECK (max_running > 0 AND max_running <= max_outstanding) |
| low_watermark | integer | no | low_watermark integer NOT NULL CHECK (low_watermark >= 0 AND low_watermark < max_outstanding) |
| refill_max | integer | no | refill_max integer NOT NULL CHECK (refill_max > 0 AND refill_max <= max_outstanding) |
| policy_version | bigint | no | policy_version bigint NOT NULL DEFAULT 1 CHECK (policy_version >= 1) |
| fairness_cursor | jsonb | no | fairness_cursor jsonb NOT NULL DEFAULT '{}' |
| fairness_cursor_schema_version | smallint | no | fairness_cursor_schema_version smallint NOT NULL DEFAULT 1 |

Constraints:
- `CONSTRAINT prc_process_lanes_lane_key_unique UNIQUE (lane_key)`
- `CHECK (fairness_cursor_schema_version > 0)`

Indexes:

#### prc_process_runs

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| kind | text | no | kind text NOT NULL CHECK (kind IN ('profile','schedule','manual','recovery','eval')) |
| trigger_key | text | yes | trigger_key text NULL |
| profile_id | bigint | yes | profile_id bigint NULL REFERENCES prc_crawlers(id) → prc_crawlers(id) ON DELETE NO ACTION @P1.6 |
| configuration_snapshot | jsonb | no | configuration_snapshot jsonb NOT NULL |
| configuration_schema_version | smallint | no | configuration_schema_version smallint NOT NULL |
| configuration_hash | bytea | no | configuration_hash bytea NOT NULL CHECK (octet_length(configuration_hash)=32) |
| requested_by | bigint | yes | requested_by bigint NULL REFERENCES users(id) → users(id) ON DELETE NO ACTION @P1.6 |
| requested_at | timestamptz | no | requested_at timestamptz NOT NULL |
| started_at | timestamptz | yes | started_at timestamptz NULL |
| finished_at | timestamptz | yes | finished_at timestamptz NULL |
| discovery_closed_at | timestamptz | yes | discovery_closed_at timestamptz NULL |
| cancel_requested_at | timestamptz | yes | cancel_requested_at timestamptz NULL |
| discovery_outcome | text | no | discovery_outcome text NOT NULL DEFAULT 'collecting' CHECK (discovery_outcome IN ('collecting','complete','incomplete')) |
| discovery_reason_code | text | yes | discovery_reason_code text NULL |
| transition_seq | bigint | no | transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0) |
| projection_version | bigint | no | projection_version bigint NOT NULL DEFAULT 0 CHECK (projection_version >= 0) |

Constraints:
- `CONSTRAINT prc_process_runs_discovery_closed_check CHECK ((discovery_outcome = 'collecting') = (discovery_closed_at IS NULL))`
- `CONSTRAINT prc_process_runs_discovery_reason_check CHECK ((discovery_outcome = 'incomplete') = (discovery_reason_code IS NOT NULL))`
- `CONSTRAINT prc_process_runs_trigger_key_unique UNIQUE (trigger_key)`
- `CHECK (configuration_schema_version > 0)`

Indexes:

#### prc_process_incidents

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: incidents. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| fingerprint | bytea | no | fingerprint bytea NOT NULL CHECK (octet_length(fingerprint)=32) |
| scope_key | text | no | scope_key text NOT NULL |
| health_epoch | bigint | yes | health_epoch bigint NULL |
| kind | text | no | kind text NOT NULL |
| state | text | no | state text NOT NULL CHECK (state IN ('open','acknowledged','resolved')) |
| first_seen_at | timestamptz | no | first_seen_at timestamptz NOT NULL |
| last_seen_at | timestamptz | no | last_seen_at timestamptz NOT NULL |
| occurrences | bigint | no | occurrences bigint NOT NULL DEFAULT 1 CHECK (occurrences >= 1) |
| transition_seq | bigint | no | transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0) |
| evidence | jsonb | no | evidence jsonb NOT NULL |
| evidence_schema_version | smallint | no | evidence_schema_version smallint NOT NULL |
| acknowledged_by | bigint | yes | acknowledged_by bigint NULL REFERENCES users(id) → users(id) ON DELETE NO ACTION @P1.6 |
| resolved_at | timestamptz | yes | resolved_at timestamptz NULL |

Constraints:
- `CHECK (evidence_schema_version > 0)`

Indexes:
- `CREATE UNIQUE INDEX prc_process_incidents_active_fingerprint_unique ON prc_process_incidents (fingerprint) WHERE state <> 'resolved';`

#### prc_process_handler_holds

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download')) |
| handler_version | text | no | handler_version text NOT NULL |
| state | text | no | state text NOT NULL DEFAULT 'healthy' CHECK (state IN ('healthy','held')) |
| version | bigint | no | version bigint NOT NULL DEFAULT 1 CHECK (version >= 1) |
| fault_signature | bytea | yes | fault_signature bytea NULL CHECK (fault_signature IS NULL OR octet_length(fault_signature)=32) |
| incident_id | uuid | yes | incident_id uuid NULL REFERENCES prc_process_incidents(id) → prc_process_incidents(id) ON DELETE NO ACTION @P1.6 |
| held_at | timestamptz | yes | held_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_process_handler_holds_stage_version_unique UNIQUE (stage, handler_version)`
- `CONSTRAINT prc_process_handler_holds_held_check CHECK (state <> 'held' OR (fault_signature IS NOT NULL AND incident_id IS NOT NULL AND held_at IS NOT NULL))`

Indexes:

#### prc_crawl_runs

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| process_run_id | uuid | no | process_run_id uuid NOT NULL REFERENCES prc_process_runs(id) → prc_process_runs(id) ON DELETE NO ACTION @P1.6 |
| listing_kind | text | no | listing_kind text NOT NULL CHECK (listing_kind IN ('fresh','archive','delta')) |
| filter_payload | jsonb | no | filter_payload jsonb NOT NULL |
| filter_schema_version | smallint | no | filter_schema_version smallint NOT NULL CHECK (filter_schema_version > 0) |
| filter_hash | bytea | no | filter_hash bytea NOT NULL CHECK (octet_length(filter_hash)=32) |
| access_policy_hash | bytea | no | access_policy_hash bytea NOT NULL CHECK (octet_length(access_policy_hash)=32) |
| adapter_version | text | no | adapter_version text NOT NULL |

Constraints:
- `CONSTRAINT prc_crawl_runs_process_run_id_unique UNIQUE (process_run_id)`

Indexes:

#### prc_crawl_blocks

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| crawl_run_id | uuid | no | crawl_run_id uuid NOT NULL REFERENCES prc_crawl_runs(id) → prc_crawl_runs(id) ON DELETE NO ACTION @P1.6 |
| parent_block_id | uuid | yes | parent_block_id uuid NULL REFERENCES prc_crawl_blocks(id) → prc_crawl_blocks(id) ON DELETE NO ACTION @P1.6 |
| block_key | text | no | block_key text NOT NULL |
| partition_payload | jsonb | no | partition_payload jsonb NOT NULL |
| partition_schema_version | smallint | no | partition_schema_version smallint NOT NULL CHECK (partition_schema_version > 0) |
| filter_hash | bytea | no | filter_hash bytea NOT NULL CHECK (octet_length(filter_hash)=32) |
| head_generation | bigint | no | head_generation bigint NOT NULL DEFAULT 1 CHECK (head_generation >= 1) |
| input_revision | bigint | no | input_revision bigint NOT NULL DEFAULT 1 CHECK (input_revision >= 1) |
| cursor | jsonb | no | cursor jsonb NOT NULL DEFAULT '{}' |
| cursor_schema_version | smallint | no | cursor_schema_version smallint NOT NULL DEFAULT 1 CHECK (cursor_schema_version > 0) |
| terminal_proof | jsonb | yes | terminal_proof jsonb NULL |
| terminal_proof_schema_version | smallint | yes | terminal_proof_schema_version smallint NULL |
| limitation_reason_code | text | yes | limitation_reason_code text NULL |

Constraints:
- `CONSTRAINT prc_crawl_blocks_run_key_unique UNIQUE (crawl_run_id, block_key)`
- `CONSTRAINT prc_crawl_blocks_proof_schema_check CHECK ((terminal_proof IS NULL) = (terminal_proof_schema_version IS NULL))`
- `CHECK (terminal_proof_schema_version > 0)`
- `CHECK ((terminal_proof IS NULL) = (terminal_proof_schema_version IS NULL))`

Indexes:

#### prc_operation_intents

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| intent_key | bytea | no | intent_key bytea NOT NULL CHECK (octet_length(intent_key)=32) |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download')) |
| subject_kind | text | no | subject_kind text NOT NULL |
| subject_id | text | no | subject_id text NOT NULL |
| semantic_policy_hash | bytea | no | semantic_policy_hash bytea NOT NULL CHECK (octet_length(semantic_policy_hash)=32) |
| access_policy_hash | bytea | no | access_policy_hash bytea NOT NULL CHECK (octet_length(access_policy_hash)=32) |
| cycle_seq | bigint | no | cycle_seq bigint NOT NULL DEFAULT 1 CHECK (cycle_seq >= 1) |
| expected_head_generation | bigint | no | expected_head_generation bigint NOT NULL CHECK (expected_head_generation >= 1) |
| state | text | no | state text NOT NULL CHECK (state IN ('pending','active','terminal')) |
| requested_at | timestamptz | no | requested_at timestamptz NOT NULL |
| next_due_at | timestamptz | yes | next_due_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_operation_intents_intent_key_unique UNIQUE (intent_key)`

Indexes:
- `CREATE UNIQUE INDEX prc_operation_intents_one_active_unique ON prc_operation_intents (stage, subject_kind, subject_id) WHERE state = 'active';`

#### prc_process_work

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download')) |
| work_key | bytea | no | work_key bytea NOT NULL CHECK (octet_length(work_key)=32) |
| work_identity | jsonb | no | work_identity jsonb NOT NULL |
| work_identity_schema_version | smallint | no | work_identity_schema_version smallint NOT NULL |
| intent_id | uuid | yes | intent_id uuid NULL REFERENCES prc_operation_intents(id) → prc_operation_intents(id) ON DELETE NO ACTION @P1.6 |
| input_revision | bigint | no | input_revision bigint NOT NULL CHECK (input_revision >= 1) |
| input_hash | bytea | no | input_hash bytea NOT NULL CHECK (octet_length(input_hash)=32) |
| input_payload | jsonb | no | input_payload jsonb NOT NULL |
| input_schema_version | smallint | no | input_schema_version smallint NOT NULL |
| state | text | no | state text NOT NULL CHECK (state IN ('ready','admitted','running','deferred','blocked','succeeded','failed_item','cancelled','superseded')) |
| reason_code | text | yes | reason_code text NULL |
| due_at | timestamptz | yes | due_at timestamptz NULL |
| provider_recovery_due_at | timestamptz | yes | provider_recovery_due_at timestamptz NULL |
| priority | smallint | no | priority smallint NOT NULL DEFAULT 100 |
| platform_id | bigint | yes | platform_id bigint NULL REFERENCES prc_platforms(id) → prc_platforms(id) ON DELETE NO ACTION @P1.6 |
| cohort_key | text | no | cohort_key text NOT NULL |
| origin_run_id | uuid | yes | origin_run_id uuid NULL REFERENCES prc_process_runs(id) → prc_process_runs(id) ON DELETE NO ACTION @P1.6 |
| crawl_block_id | uuid | yes | crawl_block_id uuid NULL REFERENCES prc_crawl_blocks(id) → prc_crawl_blocks(id) ON DELETE NO ACTION @P1.6 |
| procurement_id | bigint | yes | procurement_id bigint NULL REFERENCES prc_procurements(id) → prc_procurements(id) ON DELETE NO ACTION @P1.6 |
| document_id | bigint | yes | document_id bigint NULL REFERENCES prc_documents(id) → prc_documents(id) ON DELETE NO ACTION @P1.6 |
| document_version_id | bigint | yes | document_version_id bigint NULL → prc_document_versions(id) ON DELETE NO ACTION @P3.1 |
| analysis_window_id | uuid | yes | analysis_window_id uuid NULL → prc_analysis_windows(id) ON DELETE NO ACTION @P3.8 |
| analysis_run_id | uuid | yes | analysis_run_id uuid NULL → prc_analysis_runs(id) ON DELETE NO ACTION @P3.8 |
| generation | bigint | no | generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0) |
| owner_token | uuid | yes | owner_token uuid NULL |
| lease_until | timestamptz | yes | lease_until timestamptz NULL |
| heartbeat_at | timestamptz | yes | heartbeat_at timestamptz NULL |
| dispatch_generation | bigint | no | dispatch_generation bigint NOT NULL DEFAULT 0 CHECK (dispatch_generation >= 0) |
| dispatch_claim_until | timestamptz | yes | dispatch_claim_until timestamptz NULL |
| checkpoint | jsonb | yes | checkpoint jsonb NULL |
| checkpoint_schema_version | smallint | yes | checkpoint_schema_version smallint NULL |
| checkpoint_seq | bigint | no | checkpoint_seq bigint NOT NULL DEFAULT 0 CHECK (checkpoint_seq >= 0) |
| execution_attempts | bigint | no | execution_attempts bigint NOT NULL DEFAULT 0 CHECK (execution_attempts >= 0) |
| transport_attempts | bigint | no | transport_attempts bigint NOT NULL DEFAULT 0 CHECK (transport_attempts >= 0) |
| semantic_reasks | bigint | no | semantic_reasks bigint NOT NULL DEFAULT 0 CHECK (semantic_reasks >= 0) |
| item_failures | integer | no | item_failures integer NOT NULL DEFAULT 0 CHECK (item_failures BETWEEN 0 AND 3) |
| infrastructure_interruptions | bigint | no | infrastructure_interruptions bigint NOT NULL DEFAULT 0 CHECK (infrastructure_interruptions >= 0) |
| transition_seq | bigint | no | transition_seq bigint NOT NULL DEFAULT 0 CHECK (transition_seq >= 0) |
| created_at | timestamptz | no | created_at timestamptz NOT NULL |
| updated_at | timestamptz | no | updated_at timestamptz NOT NULL |

Constraints:
- `CONSTRAINT prc_process_work_work_key_unique UNIQUE (work_key)`
- `CONSTRAINT prc_process_work_checkpoint_schema_check CHECK ((checkpoint IS NULL) = (checkpoint_schema_version IS NULL))`
- `CONSTRAINT prc_process_work_running_lease_check CHECK ((state = 'running' AND owner_token IS NOT NULL AND lease_until IS NOT NULL) OR (state <> 'running' AND owner_token IS NULL AND lease_until IS NULL))`
- `CONSTRAINT prc_process_work_admitted_claim_check CHECK ((state = 'admitted') = (dispatch_claim_until IS NOT NULL))`
- `CONSTRAINT prc_process_work_deferred_due_check CHECK (state <> 'deferred' OR due_at IS NOT NULL)`
- `CONSTRAINT prc_process_work_due_state_check CHECK (due_at IS NULL OR state = 'deferred')`
- `CONSTRAINT prc_process_work_provider_recovery_due_check CHECK ((state = 'blocked' AND reason_code = 'provider_result_unknown') = (provider_recovery_due_at IS NOT NULL))`
- `CONSTRAINT prc_process_work_reason_check CHECK (state NOT IN ('deferred','blocked','failed_item','cancelled','superseded') OR reason_code IS NOT NULL)`
- `CONSTRAINT prc_process_work_owner_check CHECK ( (stage = 'listing' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND crawl_block_id IS NOT NULL) OR (stage IN ('detail','status_refresh') AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND procurement_id IS NOT NULL) OR (stage = 'download' AND num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1 AND document_id IS NOT NULL) )`
- `CHECK (work_identity_schema_version > 0)`
- `CHECK (input_schema_version > 0)`
- `CHECK (checkpoint_schema_version > 0)`
- `CHECK ((checkpoint IS NULL) = (checkpoint_schema_version IS NULL))`

Indexes:
- `CREATE INDEX prc_process_work_crawl_block_idx ON prc_process_work (crawl_block_id) WHERE crawl_block_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_procurement_idx ON prc_process_work (procurement_id) WHERE procurement_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_document_idx ON prc_process_work (document_id) WHERE document_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_document_version_idx ON prc_process_work (document_version_id) WHERE document_version_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_analysis_window_idx ON prc_process_work (analysis_window_id) WHERE analysis_window_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_analysis_run_idx ON prc_process_work (analysis_run_id) WHERE analysis_run_id IS NOT NULL;`
- `CREATE INDEX prc_process_work_due_idx ON prc_process_work (due_at, priority, id) WHERE state IN ('ready','deferred');`
- `CREATE INDEX prc_process_work_lease_idx ON prc_process_work (lease_until, id) WHERE state = 'running';`
- `CREATE INDEX prc_process_work_claim_idx ON prc_process_work (dispatch_claim_until, id) WHERE state = 'admitted';`
- `CREATE INDEX prc_process_work_fairness_idx ON prc_process_work (stage, cohort_key, created_at, id) WHERE state IN ('ready','deferred');`
- `CREATE INDEX prc_process_work_run_idx ON prc_process_work (origin_run_id, stage, state);`
- `CREATE INDEX prc_process_work_provider_recovery_due_idx ON prc_process_work(provider_recovery_due_at,id) WHERE state='blocked' AND reason_code='provider_result_unknown';`

#### prc_process_attempts

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| work_id | uuid | no | work_id uuid NOT NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P1.6 |
| generation | bigint | no | generation bigint NOT NULL CHECK (generation >= 1) |
| owner_token_fingerprint | bytea | no | owner_token_fingerprint bytea NOT NULL CHECK (octet_length(owner_token_fingerprint)=32) |
| handler_version | text | no | handler_version text NOT NULL |
| fault_signature | bytea | yes | fault_signature bytea NULL CHECK (fault_signature IS NULL OR octet_length(fault_signature)=32) |
| fault_policy_checked_at | timestamptz | yes | fault_policy_checked_at timestamptz NULL |
| started_at | timestamptz | no | started_at timestamptz NOT NULL |
| finished_at | timestamptz | yes | finished_at timestamptz NULL |
| last_heartbeat_at | timestamptz | yes | last_heartbeat_at timestamptz NULL |
| last_checkpoint_at | timestamptz | yes | last_checkpoint_at timestamptz NULL |
| terminal_kind | text | yes | terminal_kind text NULL CHECK (terminal_kind IN ('succeeded','partial','deferred','blocked','failed_item','interrupted','cancelled','superseded','lost_ownership')) |
| worker_host | text | no | worker_host text NOT NULL |
| worker_pid | integer | no | worker_pid integer NOT NULL |

Constraints:
- `CONSTRAINT prc_process_attempts_work_generation_unique UNIQUE (work_id, generation)`

Indexes:
- `CREATE INDEX prc_process_attempts_fault_idx ON prc_process_attempts (handler_version, fault_signature, finished_at) WHERE fault_signature IS NOT NULL;`
- `CREATE INDEX prc_process_attempts_work_fault_idx ON prc_process_attempts(work_id,handler_version) WHERE fault_signature IS NOT NULL AND finished_at IS NOT NULL;`
- `CREATE INDEX prc_process_attempts_fault_policy_pending_idx ON prc_process_attempts (finished_at,id) WHERE fault_signature IS NOT NULL AND fault_policy_checked_at IS NULL;`

#### prc_admission_groups

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| work_id | uuid | no | work_id uuid NOT NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P1.6 |
| dispatch_generation | bigint | no | dispatch_generation bigint NOT NULL CHECK (dispatch_generation >= 1) |
| required_lane_set_hash | bytea | no | required_lane_set_hash bytea NOT NULL CHECK (octet_length(required_lane_set_hash)=32) |
| attempt_id | uuid | yes | attempt_id uuid NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P1.6 |
| state | text | no | state text NOT NULL CHECK (state IN ('admitted','running','released')) |
| version | bigint | no | version bigint NOT NULL DEFAULT 1 CHECK (version >= 1) |
| admitted_at | timestamptz | no | admitted_at timestamptz NOT NULL |
| released_at | timestamptz | yes | released_at timestamptz NULL |
| release_reason | text | yes | release_reason text NULL |

Constraints:

Indexes:
- `CREATE UNIQUE INDEX prc_admission_groups_active_work_unique ON prc_admission_groups (work_id) WHERE state <> 'released';`

#### prc_process_reservations

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| admission_group_id | uuid | no | admission_group_id uuid NOT NULL REFERENCES prc_admission_groups(id) ON DELETE CASCADE → prc_admission_groups(id) ON DELETE CASCADE @P1.6 |
| lane_id | uuid | no | lane_id uuid NOT NULL REFERENCES prc_process_lanes(id) → prc_process_lanes(id) ON DELETE NO ACTION @P1.6 |
| work_id | uuid | no | work_id uuid NOT NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P1.6 |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download')) |
| state | text | no | state text NOT NULL CHECK (state IN ('admitted','running')) |

Constraints:
- `PRIMARY KEY (admission_group_id, lane_id)`

Indexes:
- `CREATE INDEX prc_process_reservations_lane_state_idx ON prc_process_reservations (lane_id, state);`

#### prc_process_run_work

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| run_id | uuid | no | run_id uuid NOT NULL REFERENCES prc_process_runs(id) → prc_process_runs(id) ON DELETE NO ACTION @P1.6 |
| work_id | uuid | no | work_id uuid NOT NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P1.6 |
| relation | text | no | relation text NOT NULL CHECK (relation IN ('created','discovered','observes')) |
| accounting_role | text | no | accounting_role text NOT NULL CHECK (accounting_role IN ('required','observes')) |

Constraints:
- `PRIMARY KEY (run_id, work_id)`

Indexes:

#### prc_process_transitions

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: journal. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| event_id | uuid | no | event_id uuid PRIMARY KEY |
| entity_kind | text | no | entity_kind text NOT NULL CHECK (entity_kind IN ('work','run','control','incident')) |
| work_id | uuid | yes | work_id uuid NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P1.6 |
| run_id | uuid | yes | run_id uuid NULL REFERENCES prc_process_runs(id) → prc_process_runs(id) ON DELETE NO ACTION @P1.6 |
| control_id | uuid | yes | control_id uuid NULL REFERENCES prc_control_scopes(id) → prc_control_scopes(id) ON DELETE NO ACTION @P1.6 |
| incident_id | uuid | yes | incident_id uuid NULL REFERENCES prc_process_incidents(id) → prc_process_incidents(id) ON DELETE NO ACTION @P1.6 |
| entity_seq | bigint | no | entity_seq bigint NOT NULL CHECK (entity_seq >= 1) |
| previous_state | text | yes | previous_state text NULL |
| new_state | text | no | new_state text NOT NULL |
| reason_code | text | yes | reason_code text NULL |
| actor_kind | text | no | actor_kind text NOT NULL CHECK (actor_kind IN ('system','operator','worker','reconciler')) |
| actor_id | bigint | yes | actor_id bigint NULL |
| generation | bigint | yes | generation bigint NULL |
| details | jsonb | yes | details jsonb NULL |
| details_schema_version | smallint | yes | details_schema_version smallint NULL |
| occurred_at | timestamptz | no | occurred_at timestamptz NOT NULL DEFAULT clock_timestamp() |
| schema_version | smallint | no | schema_version smallint NOT NULL |

Constraints:
- `CONSTRAINT prc_process_transitions_details_schema_check CHECK ((details IS NULL) = (details_schema_version IS NULL))`
- `CONSTRAINT prc_process_transitions_entity_check CHECK ( num_nonnulls(work_id, run_id, control_id, incident_id) = 1 AND ((entity_kind='work' AND work_id IS NOT NULL) OR (entity_kind='run' AND run_id IS NOT NULL) OR (entity_kind='control' AND control_id IS NOT NULL) OR (entity_kind='incident' AND incident_id IS NOT NULL)) )`
- `CHECK (details_schema_version > 0)`
- `CHECK (schema_version > 0)`
- `CHECK ((details IS NULL) = (details_schema_version IS NULL))`

Indexes:
- `CREATE UNIQUE INDEX prc_process_transitions_control_seq_unique ON prc_process_transitions (control_id, entity_seq) WHERE control_id IS NOT NULL;`
- `CREATE INDEX prc_process_transitions_work_time_idx ON prc_process_transitions (work_id, occurred_at, event_id);`
- `CREATE UNIQUE INDEX prc_process_transitions_incident_seq_unique ON prc_process_transitions (incident_id, entity_seq) WHERE incident_id IS NOT NULL;`
- `CREATE UNIQUE INDEX prc_process_transitions_work_seq_unique ON prc_process_transitions (work_id, entity_seq) WHERE work_id IS NOT NULL;`
- `CREATE UNIQUE INDEX prc_process_transitions_run_seq_unique ON prc_process_transitions (run_id, entity_seq) WHERE run_id IS NOT NULL;`
- `CREATE INDEX prc_process_transitions_run_time_idx ON prc_process_transitions (run_id, occurred_at, event_id);`

#### prc_process_outbox

Create: P1.6; activation boundary: P1.10; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| message_id | uuid | no | message_id uuid PRIMARY KEY |
| dedup_key | text | no | dedup_key text NOT NULL |
| kind | text | no | kind text NOT NULL CHECK (kind IN ('work_wake','feeder_wake','projection')) |
| payload | jsonb | no | payload jsonb NOT NULL |
| payload_schema_version | smallint | no | payload_schema_version smallint NOT NULL |
| available_at | timestamptz | no | available_at timestamptz NOT NULL |
| claim_token | uuid | yes | claim_token uuid NULL |
| claim_until | timestamptz | yes | claim_until timestamptz NULL |
| published_at | timestamptz | yes | published_at timestamptz NULL |
| attempts | bigint | no | attempts bigint NOT NULL DEFAULT 0 CHECK (attempts >= 0) |
| last_error_code | text | yes | last_error_code text NULL |

Constraints:
- `CONSTRAINT prc_process_outbox_dedup_key_unique UNIQUE (dedup_key)`
- `CHECK (payload_schema_version > 0)`

Indexes:
- `CREATE INDEX prc_process_outbox_pending_idx ON prc_process_outbox (kind, available_at, message_id) WHERE published_at IS NULL;`

#### prc_process_daemon_heartbeats

Create: P1.6; activation boundary: P1.10; owner: `Repositories/Procurement/Process`; retention: technical. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| instance_id | uuid | no | instance_id uuid PRIMARY KEY |
| daemon | text | no | daemon text NOT NULL CHECK(daemon IN ('relay','reconcile','feed','project')) |
| host | text | no | host text NOT NULL |
| pid | integer | no | pid integer NOT NULL |
| started_at | timestamptz | no | started_at timestamptz NOT NULL |
| last_tick_at | timestamptz | no | last_tick_at timestamptz NOT NULL |
| last_error_code | text | yes | last_error_code text NULL |

Constraints:

Indexes:

#### prc_outbound_operations

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| operation_key | uuid | no | operation_key uuid NOT NULL UNIQUE |
| kind | text | no | kind text NOT NULL CHECK(kind IN ('legacy_bridge','diagnostic','probe')) |
| platform_id | bigint | yes | platform_id bigint NULL REFERENCES prc_platforms(id) → prc_platforms(id) ON DELETE NO ACTION @P2.1 |
| caller_code | text | no | caller_code text NOT NULL |
| state | text | no | state text NOT NULL CHECK(state IN ('open','closed','expired')) |
| owner_token | uuid | no | owner_token uuid NOT NULL |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>=1) |
| lease_until | timestamptz | no | lease_until timestamptz NOT NULL |
| deadline_at | timestamptz | no | deadline_at timestamptz NOT NULL |
| created_at | timestamptz | no | created_at timestamptz NOT NULL |
| finished_at | timestamptz | yes | finished_at timestamptz NULL |
| probe_token | uuid | yes | probe_token uuid NULL |
| policy_hash | bytea | no | policy_hash bytea NOT NULL CHECK(octet_length(policy_hash)=32) |

Constraints:
- `CHECK(lease_until<=deadline_at)`
- `CHECK((kind='probe') = (probe_token IS NOT NULL))`
- `CHECK((state='open') = (finished_at IS NULL))`

Indexes:
- `CREATE INDEX prc_outbound_operations_reap ON prc_outbound_operations(lease_until) WHERE state='open';`

#### prc_outbound_operation_controls

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| operation_id | uuid | no | operation_id uuid NOT NULL REFERENCES prc_outbound_operations(id) → prc_outbound_operations(id) ON DELETE NO ACTION @P2.1 |
| control_scope_id | uuid | no | control_scope_id uuid NOT NULL REFERENCES prc_control_scopes(id) → prc_control_scopes(id) ON DELETE NO ACTION @P2.1 |
| control_version | bigint | no | control_version bigint NOT NULL |

Constraints:
- `PRIMARY KEY(operation_id,control_scope_id)`

Indexes:

#### prc_network_budgets

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| scope_key | text | no | scope_key text NOT NULL UNIQUE |
| emission_interval_us | bigint | no | emission_interval_us bigint NOT NULL CHECK(emission_interval_us>0) |
| burst | smallint | no | burst smallint NOT NULL DEFAULT 1 CHECK(burst>=1) |
| max_inflight | smallint | no | max_inflight smallint NOT NULL CHECK(max_inflight>=1) |
| tat | timestamptz | no | tat timestamptz NOT NULL DEFAULT '-infinity' |
| send_tat | timestamptz | no | send_tat timestamptz NOT NULL DEFAULT '-infinity' |
| not_before | timestamptz | no | not_before timestamptz NOT NULL DEFAULT '-infinity' |
| policy_version | bigint | no | policy_version bigint NOT NULL CHECK(policy_version>=1) |

Constraints:

Indexes:

#### prc_network_demands

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| physical_request_id | uuid | no | physical_request_id uuid NOT NULL UNIQUE |
| work_id | uuid | yes | work_id uuid NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P2.1 |
| attempt_id | uuid | yes | attempt_id uuid NULL |
| work_generation | bigint | yes | work_generation bigint NULL |
| operation_id | uuid | yes | operation_id uuid NULL REFERENCES prc_outbound_operations(id) → prc_outbound_operations(id) ON DELETE NO ACTION @P2.1 |
| operation_generation | bigint | yes | operation_generation bigint NULL |
| origin_budget_id | uuid | no | origin_budget_id uuid NOT NULL REFERENCES prc_network_budgets(id) → prc_network_budgets(id) ON DELETE NO ACTION @P2.1 |
| route_id | uuid | no | route_id uuid NOT NULL REFERENCES prc_network_routes(id) → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |
| target_id | uuid | no | target_id uuid NOT NULL REFERENCES prc_source_targets(id) → prc_source_targets(id) ON DELETE NO ACTION @P2.1 |
| traffic_class | text | no | traffic_class text NOT NULL CHECK(traffic_class IN ('fresh_listing','status_refresh','detail','archive_listing','download','reference_sync','diagnostic')) |
| purpose | text | no | purpose text NOT NULL CHECK(purpose IN ('ordinary','probe','diagnostic')) |
| ticket_seq | bigint | yes | ticket_seq bigint GENERATED ALWAYS AS IDENTITY UNIQUE |
| registered_at | timestamptz | no | registered_at timestamptz NOT NULL |
| renewed_at | timestamptz | no | renewed_at timestamptz NOT NULL |
| expires_at | timestamptz | no | expires_at timestamptz NOT NULL |
| operation_deadline | timestamptz | no | operation_deadline timestamptz NOT NULL |
| state | text | no | state text NOT NULL CHECK(state IN ('queued','granted','cancelled','expired')) |

Constraints:
- `FOREIGN KEY(attempt_id,work_id) REFERENCES prc_process_attempts(id,work_id)`
- `CHECK((work_id IS NOT NULL AND attempt_id IS NOT NULL AND work_generation IS NOT NULL AND work_generation>=1 AND operation_id IS NULL AND operation_generation IS NULL) OR (work_id IS NULL AND attempt_id IS NULL AND work_generation IS NULL AND operation_id IS NOT NULL AND operation_generation IS NOT NULL AND operation_generation>=1))`
- `CHECK(expires_at<=operation_deadline)`
- `CONSTRAINT prc_attempt_work_pair UNIQUE (id, work_id) on prc_process_attempts, installed before composite attempt/work FK`

Indexes:
- `CREATE UNIQUE INDEX prc_network_demands_attempt_active ON prc_network_demands(attempt_id) WHERE state='queued' AND attempt_id IS NOT NULL;`
- `CREATE UNIQUE INDEX prc_network_demands_operation_active ON prc_network_demands(operation_id) WHERE state='queued' AND operation_id IS NOT NULL;`
- `CREATE INDEX prc_network_demands_fifo ON prc_network_demands(origin_budget_id,ticket_seq) WHERE state='queued';`
- `CREATE INDEX prc_network_demands_class ON prc_network_demands(origin_budget_id,traffic_class,id);`

#### prc_network_permits

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| demand_id | uuid | no | demand_id uuid NOT NULL UNIQUE REFERENCES prc_network_demands(id) → prc_network_demands(id) ON DELETE NO ACTION @P2.1 |
| slot_at | timestamptz | no | slot_at timestamptz NOT NULL |
| expires_at | timestamptz | no | expires_at timestamptz NOT NULL |
| authorized_at | timestamptz | yes | authorized_at timestamptz NULL |
| state | text | no | state text NOT NULL CHECK(state IN ('reserved','sent','released','expired')) |
| policy_versions | jsonb | no | policy_versions jsonb NOT NULL |
| policy_schema_version | smallint | no | policy_schema_version smallint NOT NULL CHECK(policy_schema_version>=1) |
| health_epochs | jsonb | no | health_epochs jsonb NOT NULL |
| health_epochs_schema_version | smallint | no | health_epochs_schema_version smallint NOT NULL CHECK(health_epochs_schema_version>=1) |
| created_at | timestamptz | no | created_at timestamptz NOT NULL |

Constraints:
- `CHECK(expires_at>slot_at)`
- `CHECK(state<>'reserved' OR authorized_at IS NULL)`
- `CHECK(state<>'sent' OR authorized_at IS NOT NULL)`

Indexes:
- `CREATE INDEX prc_network_permits_active ON prc_network_permits(expires_at) WHERE state IN ('reserved','sent');`
- `CREATE INDEX prc_network_permits_authorized ON prc_network_permits(authorized_at) WHERE authorized_at IS NOT NULL;`

#### prc_network_permit_scopes

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| permit_id | uuid | no | permit_id uuid NOT NULL REFERENCES prc_network_permits(id) ON DELETE CASCADE → prc_network_permits(id) ON DELETE CASCADE @P2.1 |
| budget_id | uuid | no | budget_id uuid NOT NULL REFERENCES prc_network_budgets(id) → prc_network_budgets(id) ON DELETE NO ACTION @P2.1 |

Constraints:
- `PRIMARY KEY(permit_id,budget_id)`

Indexes:
- `CREATE INDEX prc_network_permit_scopes_budget ON prc_network_permit_scopes(budget_id);`

#### prc_request_attempts

Create: P1.6; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: execution#s04.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| request_id | uuid | no | request_id uuid PRIMARY KEY |
| attempt_id | uuid | no | attempt_id uuid NOT NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P1.6 |
| request_seq | bigint | no | request_seq bigint NOT NULL CHECK(request_seq>=1) |
| target_id | uuid | yes | target_id uuid NULL → prc_source_targets(id) ON DELETE NO ACTION @P2.1 |
| route_id | uuid | yes | route_id uuid NULL → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |
| started_at | timestamptz | no | started_at timestamptz NOT NULL |
| sent_at | timestamptz | yes | sent_at timestamptz NULL |
| finished_at | timestamptz | yes | finished_at timestamptz NULL |
| phase | text | no | phase text NOT NULL |
| outcome | text | yes | outcome text NULL |
| bytes | bigint | no | bytes bigint NOT NULL DEFAULT 0 CHECK(bytes>=0) |
| status | smallint | yes | status smallint NULL |
| duration_ms | bigint | yes | duration_ms bigint NULL CHECK(duration_ms>=0) |
| epochs | jsonb | no | epochs jsonb NOT NULL |
| epochs_schema_version | smallint | no | epochs_schema_version smallint NOT NULL |
| artifacts | jsonb | yes | artifacts jsonb NULL |
| artifacts_schema_version | smallint | yes | artifacts_schema_version smallint NULL |

Constraints:
- `UNIQUE(attempt_id,request_seq)`
- `CHECK (epochs_schema_version > 0)`
- `CHECK (artifacts_schema_version > 0)`
- `CHECK ((artifacts IS NULL) = (artifacts_schema_version IS NULL))`

Indexes:

#### prc_observations

Create: P1.6; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: execution#s04.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| observation_id | uuid | no | observation_id uuid PRIMARY KEY |
| request_id | uuid | yes | request_id uuid NULL REFERENCES prc_request_attempts(request_id) → prc_request_attempts(request_id) ON DELETE NO ACTION @P1.6 |
| attempt_id | uuid | yes | attempt_id uuid NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P1.6 |
| kind | text | no | kind text NOT NULL |
| reason_code | text | no | reason_code text NOT NULL |
| input_hash | bytea | no | input_hash bytea NOT NULL CHECK(octet_length(input_hash)=32) |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>=0) |
| epochs | jsonb | no | epochs jsonb NOT NULL |
| epochs_schema_version | smallint | no | epochs_schema_version smallint NOT NULL |
| stale | boolean | no | stale boolean NOT NULL DEFAULT false |
| details | jsonb | yes | details jsonb NULL |
| details_schema_version | smallint | yes | details_schema_version smallint NULL |
| occurred_at | timestamptz | no | occurred_at timestamptz NOT NULL |

Constraints:
- `CHECK (epochs_schema_version > 0)`
- `CHECK (details_schema_version > 0)`
- `CHECK ((details IS NULL) = (details_schema_version IS NULL))`

Indexes:

#### prc_observation_applications

Create: P1.6; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: requests. Contract: execution#s04.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| observation_id | uuid | no | observation_id uuid NOT NULL REFERENCES prc_observations(observation_id) → prc_observations(observation_id) ON DELETE NO ACTION @P1.6 |
| projection_key | text | no | projection_key text NOT NULL |
| reducer_version | bigint | no | reducer_version bigint NOT NULL CHECK(reducer_version>=1) |
| applied_at | timestamptz | no | applied_at timestamptz NOT NULL |

Constraints:
- `PRIMARY KEY(observation_id,projection_key)`

Indexes:

#### prc_process_projection_states

Create: P1.14; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| projector_key | text | no | projector_key text PRIMARY KEY |
| mode | text | no | mode text NOT NULL CHECK(mode IN ('active','maintenance')) |
| active_generation | bigint | no | active_generation bigint NOT NULL CHECK(active_generation>0) |
| catalog_hash | bytea | no | catalog_hash bytea NOT NULL CHECK(octet_length(catalog_hash)=32) |
| history_complete | boolean | no | history_complete boolean NOT NULL DEFAULT true |
| history_pruned_at | timestamptz | yes | history_pruned_at timestamptz NULL |
| maintenance_token | uuid | yes | maintenance_token uuid NULL |
| maintenance_started_at | timestamptz | yes | maintenance_started_at timestamptz NULL |
| rebuilt_at | timestamptz | yes | rebuilt_at timestamptz NULL |

Constraints:
- `CHECK((mode='maintenance')=(maintenance_token IS NOT NULL))`
- `CHECK((mode='maintenance')=(maintenance_started_at IS NOT NULL))`
- `CHECK(history_complete=(history_pruned_at IS NULL))`

Indexes:

#### prc_process_metric_totals

Create: P1.14; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| projector_key | text | no | projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key) → prc_process_projection_states(projector_key) ON DELETE NO ACTION @P1.14 |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>0) |
| metric_key | text | no | metric_key text NOT NULL |
| dimension_key | text | no | dimension_key text NOT NULL |
| value | bigint | no | value bigint NOT NULL CHECK(value>=0) |
| updated_at | timestamptz | no | updated_at timestamptz NOT NULL |

Constraints:
- `PRIMARY KEY(projector_key,generation,metric_key,dimension_key)`

Indexes:

#### prc_process_projection_receipts

Create: P1.14; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| projector_key | text | no | projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key) → prc_process_projection_states(projector_key) ON DELETE NO ACTION @P1.14 |
| event_id | uuid | no | event_id uuid NOT NULL REFERENCES prc_process_transitions(event_id) ON DELETE RESTRICT → prc_process_transitions(event_id) ON DELETE NO ACTION @P1.14 |
| applied_at | timestamptz | no | applied_at timestamptz NOT NULL |

Constraints:
- `PRIMARY KEY(projector_key,event_id)`

Indexes:

#### prc_network_routes

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| route_key | text | no | route_key text NOT NULL UNIQUE |
| kind | text | no | kind text NOT NULL CHECK(kind IN ('direct','proxy')) |
| egress_id | text | no | egress_id text NOT NULL |
| independence_group | text | yes | independence_group text NULL |
| enabled | boolean | no | enabled boolean NOT NULL |
| disabled_at | timestamptz | yes | disabled_at timestamptz NULL |
| disabled_reason | text | yes | disabled_reason text NULL |
| secret_ref | text | yes | secret_ref text NULL |
| credential_version | bigint | no | credential_version bigint NOT NULL CHECK(credential_version>=1) |

Constraints:

Indexes:

#### prc_network_route_profiles

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| profile_key | text | no | profile_key text NOT NULL UNIQUE |
| policy_version | bigint | no | policy_version bigint NOT NULL CHECK(policy_version>=1) |

Constraints:

Indexes:

#### prc_source_targets

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| platform_id | bigint | no | platform_id bigint NOT NULL REFERENCES prc_platforms(id) → prc_platforms(id) ON DELETE NO ACTION @P2.1 |
| target_key | text | no | target_key text NOT NULL UNIQUE |
| canonical_origin | text | no | canonical_origin text NOT NULL |
| capability | text | no | capability text NOT NULL |
| endpoint_policy | jsonb | no | endpoint_policy jsonb NOT NULL |
| endpoint_policy_schema_version | smallint | no | endpoint_policy_schema_version smallint NOT NULL |
| policy_version | bigint | no | policy_version bigint NOT NULL CHECK(policy_version>=1) |
| retired_at | timestamptz | yes | retired_at timestamptz NULL |

Constraints:
- `CHECK (endpoint_policy_schema_version > 0)`

Indexes:

#### prc_route_health

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| route_id | uuid | no | route_id uuid NOT NULL UNIQUE REFERENCES prc_network_routes(id) → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |
| state | text | no | state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')) |
| health_epoch | bigint | no | health_epoch bigint NOT NULL CHECK(health_epoch>=1) |
| state_version | bigint | no | state_version bigint NOT NULL CHECK(state_version>=1) |
| reason_code | text | yes | reason_code text NULL |
| last_success_at | timestamptz | yes | last_success_at timestamptz NULL |
| last_failure_at | timestamptz | yes | last_failure_at timestamptz NULL |
| open_until | timestamptz | yes | open_until timestamptz NULL |
| probe_token | uuid | yes | probe_token uuid NULL |
| probe_until | timestamptz | yes | probe_until timestamptz NULL |
| stable_since | timestamptz | yes | stable_since timestamptz NULL |
| recent_openings | bigint | no | recent_openings bigint NOT NULL CHECK(recent_openings>=0) |
| consecutive_failures | bigint | no | consecutive_failures bigint NOT NULL CHECK(consecutive_failures>=0) |

Constraints:

Indexes:

#### prc_route_target_health

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| route_id | uuid | no | route_id uuid NOT NULL REFERENCES prc_network_routes(id) → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |
| target_id | uuid | no | target_id uuid NOT NULL REFERENCES prc_source_targets(id) → prc_source_targets(id) ON DELETE NO ACTION @P2.1 |
| state | text | no | state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')) |
| health_epoch | bigint | no | health_epoch bigint NOT NULL CHECK(health_epoch>=1) |
| state_version | bigint | no | state_version bigint NOT NULL CHECK(state_version>=1) |
| reason_code | text | yes | reason_code text NULL |
| last_success_at | timestamptz | yes | last_success_at timestamptz NULL |
| last_failure_at | timestamptz | yes | last_failure_at timestamptz NULL |
| open_until | timestamptz | yes | open_until timestamptz NULL |
| probe_token | uuid | yes | probe_token uuid NULL |
| probe_until | timestamptz | yes | probe_until timestamptz NULL |
| stable_since | timestamptz | yes | stable_since timestamptz NULL |
| recent_openings | bigint | no | recent_openings bigint NOT NULL CHECK(recent_openings>=0) |
| consecutive_failures | bigint | no | consecutive_failures bigint NOT NULL CHECK(consecutive_failures>=0) |

Constraints:
- `UNIQUE(route_id,target_id)`

Indexes:

#### prc_target_health

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| target_id | uuid | no | target_id uuid NOT NULL UNIQUE REFERENCES prc_source_targets(id) → prc_source_targets(id) ON DELETE NO ACTION @P2.1 |
| state | text | no | state text NOT NULL CHECK(state IN ('unknown','closed','open','half_open')) |
| availability | text | no | availability text NOT NULL CHECK(availability IN ('unknown','available','suspect','unavailable')) |
| health_epoch | bigint | no | health_epoch bigint NOT NULL CHECK(health_epoch>=1) |
| state_version | bigint | no | state_version bigint NOT NULL CHECK(state_version>=1) |
| reason_code | text | yes | reason_code text NULL |
| last_success_at | timestamptz | yes | last_success_at timestamptz NULL |
| last_failure_at | timestamptz | yes | last_failure_at timestamptz NULL |
| open_until | timestamptz | yes | open_until timestamptz NULL |
| probe_token | uuid | yes | probe_token uuid NULL |
| probe_until | timestamptz | yes | probe_until timestamptz NULL |
| stable_since | timestamptz | yes | stable_since timestamptz NULL |
| recent_openings | bigint | no | recent_openings bigint NOT NULL CHECK(recent_openings>=0) |

Constraints:

Indexes:

#### prc_adapter_health

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| platform_id | bigint | no | platform_id bigint NOT NULL REFERENCES prc_platforms(id) → prc_platforms(id) ON DELETE NO ACTION @P2.1 |
| capability | text | no | capability text NOT NULL |
| adapter_version | text | no | adapter_version text NOT NULL |
| contract_version | text | no | contract_version text NOT NULL |
| state | text | no | state text NOT NULL CHECK(state IN ('healthy','suspect','held','validating')) |
| health_epoch | bigint | no | health_epoch bigint NOT NULL CHECK(health_epoch>=1) |
| state_version | bigint | no | state_version bigint NOT NULL CHECK(state_version>=1) |
| reason_code | text | yes | reason_code text NULL |
| last_success_at | timestamptz | yes | last_success_at timestamptz NULL |
| last_failure_at | timestamptz | yes | last_failure_at timestamptz NULL |
| open_until | timestamptz | yes | open_until timestamptz NULL |
| probe_token | uuid | yes | probe_token uuid NULL |
| probe_until | timestamptz | yes | probe_until timestamptz NULL |
| stable_since | timestamptz | yes | stable_since timestamptz NULL |
| recent_openings | bigint | no | recent_openings bigint NOT NULL CHECK(recent_openings>=0) |

Constraints:
- `UNIQUE(platform_id,capability,adapter_version,contract_version)`

Indexes:

#### prc_detail_snapshots

Create: P2.14; activation boundary: P2.18; owner: `Repositories/Procurement/Crawler`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| procurement_id | bigint | no | procurement_id bigint NOT NULL REFERENCES prc_procurements(id) → prc_procurements(id) ON DELETE NO ACTION @P2.14 |
| input_revision | bigint | no | input_revision bigint NOT NULL CHECK(input_revision>=1) |
| part_key | text | no | part_key text NOT NULL |
| completeness | text | no | completeness text NOT NULL |
| result_ref | jsonb | no | result_ref jsonb NOT NULL |
| result_ref_schema_version | smallint | no | result_ref_schema_version smallint NOT NULL |
| source_version | text | yes | source_version text NULL |
| created_at | timestamptz | no | created_at timestamptz NOT NULL |

Constraints:
- `UNIQUE(procurement_id,input_revision,part_key)`
- `CHECK (result_ref_schema_version > 0)`

Indexes:

#### prc_status_refreshes

Create: P2.14; activation boundary: P2.18; owner: `Repositories/Procurement/Crawler`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| procurement_id | bigint | no | procurement_id bigint NOT NULL REFERENCES prc_procurements(id) → prc_procurements(id) ON DELETE NO ACTION @P2.14 |
| intent_id | uuid | no | intent_id uuid NOT NULL REFERENCES prc_operation_intents(id) → prc_operation_intents(id) ON DELETE NO ACTION @P2.14 |
| cycle_seq | bigint | no | cycle_seq bigint NOT NULL CHECK(cycle_seq>=1) |
| next_due_at | timestamptz | no | next_due_at timestamptz NOT NULL |
| source_version | text | yes | source_version text NULL |
| observed_at | timestamptz | yes | observed_at timestamptz NULL |

Constraints:
- `UNIQUE(procurement_id,cycle_seq)`

Indexes:

#### prc_procurement_documents

Create: P2.14; activation boundary: P2.18; owner: `Repositories/Procurement/Crawler`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| procurement_id | bigint | no | procurement_id bigint NOT NULL REFERENCES prc_procurements(id) → prc_procurements(id) ON DELETE NO ACTION @P2.14 |
| document_id | bigint | no | document_id bigint NOT NULL REFERENCES prc_documents(id) → prc_documents(id) ON DELETE NO ACTION @P2.14 |

Constraints:
- `PRIMARY KEY(procurement_id,document_id)`

Indexes:

#### prc_storage_domains

Create: P3.1; activation boundary: P3.5; owner: `Repositories/Procurement/Document`; retention: artifacts. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| domain_key | text | no | domain_key text NOT NULL UNIQUE |
| capacity_bytes | bigint | no | capacity_bytes bigint NOT NULL CHECK(capacity_bytes>0) |
| high_watermark_bytes | bigint | no | high_watermark_bytes bigint NOT NULL CHECK(high_watermark_bytes>0 AND high_watermark_bytes<=capacity_bytes) |
| accounted_durable_bytes | bigint | no | accounted_durable_bytes bigint NOT NULL CHECK(accounted_durable_bytes>=0) |
| policy_version | bigint | no | policy_version bigint NOT NULL CHECK(policy_version>=1) |

Constraints:

Indexes:

#### prc_storage_reservations

Create: P3.1; activation boundary: P3.5; owner: `Repositories/Procurement/Document`; retention: artifacts. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| domain_id | uuid | no | domain_id uuid NOT NULL REFERENCES prc_storage_domains(id) → prc_storage_domains(id) ON DELETE NO ACTION @P3.1 |
| work_id | uuid | yes | work_id uuid NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P3.1 |
| attempt_id | uuid | yes | attempt_id uuid NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P3.1 |
| operation_id | uuid | yes | operation_id uuid NULL REFERENCES prc_outbound_operations(id) → prc_outbound_operations(id) ON DELETE NO ACTION @P3.1 |
| upload_intent_id | uuid | no | upload_intent_id uuid NOT NULL |
| owner_token | uuid | no | owner_token uuid NOT NULL |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>=1) |
| byte_upper_bound | bigint | no | byte_upper_bound bigint NOT NULL CHECK(byte_upper_bound>=0) |
| phase | text | no | phase text NOT NULL |
| status | text | no | status text NOT NULL |
| cleanup_verified_at | timestamptz | yes | cleanup_verified_at timestamptz NULL |

Constraints:

Indexes:

#### prc_document_blobs

Create: P3.1; activation boundary: P3.5; owner: `Repositories/Procurement/Document`; retention: artifacts. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| storage_domain_id | uuid | no | storage_domain_id uuid NOT NULL REFERENCES prc_storage_domains(id) → prc_storage_domains(id) ON DELETE NO ACTION @P3.1 |
| content_hash | bytea | no | content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32) |
| disk | text | no | disk text NOT NULL |
| object_key | text | no | object_key text NOT NULL |
| size_bytes | bigint | no | size_bytes bigint NOT NULL CHECK(size_bytes>=0) |
| verified_at | timestamptz | no | verified_at timestamptz NOT NULL |
| deletion_generation | bigint | no | deletion_generation bigint NOT NULL DEFAULT 0 CHECK(deletion_generation>=0) |
| deletion_marked_at | timestamptz | yes | deletion_marked_at timestamptz NULL |

Constraints:
- `UNIQUE(storage_domain_id,content_hash)`
- `UNIQUE(disk,object_key)`

Indexes:

#### prc_analysis_runs

Create: P3.8; activation boundary: P3.13; owner: `Repositories/Procurement/Ai`; retention: business. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| document_version_id | bigint | no | document_version_id bigint NOT NULL REFERENCES prc_document_versions(id) → prc_document_versions(id) ON DELETE NO ACTION @P3.8 |
| representation_id | bigint | no | representation_id bigint NOT NULL REFERENCES prc_document_representations(id) → prc_document_representations(id) ON DELETE NO ACTION @P3.8 |
| representation_hash | bytea | no | representation_hash bytea NOT NULL CHECK(octet_length(representation_hash)=32) |
| recipe_json | jsonb | no | recipe_json jsonb NOT NULL |
| recipe_schema_version | smallint | no | recipe_schema_version smallint NOT NULL |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32) |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>=1) |
| expected_document_head_generation | bigint | no | expected_document_head_generation bigint NOT NULL CHECK(expected_document_head_generation>=1) |
| expected_analysis_generation | bigint | no | expected_analysis_generation bigint NOT NULL CHECK(expected_analysis_generation>=1) |
| requested_reason | text | no | requested_reason text NOT NULL |
| supersedes_run_id | uuid | yes | supersedes_run_id uuid NULL REFERENCES prc_analysis_runs(id) → prc_analysis_runs(id) ON DELETE NO ACTION @P3.8 |
| publication_status | text | no | publication_status text NOT NULL |

Constraints:
- `CHECK (recipe_schema_version > 0)`

Indexes:

#### prc_analysis_windows

Create: P3.8; activation boundary: P3.13; owner: `Repositories/Procurement/Ai`; retention: business. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| run_id | uuid | no | run_id uuid NOT NULL REFERENCES prc_analysis_runs(id) → prc_analysis_runs(id) ON DELETE NO ACTION @P3.8 |
| window_key | text | no | window_key text NOT NULL |
| input_hash | bytea | no | input_hash bytea NOT NULL CHECK(octet_length(input_hash)=32) |
| input_range | jsonb | no | input_range jsonb NOT NULL |
| input_range_schema_version | smallint | no | input_range_schema_version smallint NOT NULL |
| expected_coverage | jsonb | no | expected_coverage jsonb NOT NULL |
| expected_coverage_schema_version | smallint | no | expected_coverage_schema_version smallint NOT NULL |
| state | text | no | state text NOT NULL |
| checkpoint_version | bigint | no | checkpoint_version bigint NOT NULL CHECK(checkpoint_version>=0) |

Constraints:
- `UNIQUE(run_id,window_key)`
- `CHECK (input_range_schema_version > 0)`
- `CHECK (expected_coverage_schema_version > 0)`

Indexes:

#### prc_analysis_chunks

Create: P3.8; activation boundary: P3.13; owner: `Repositories/Procurement/Ai`; retention: business. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| run_id | uuid | no | run_id uuid NOT NULL REFERENCES prc_analysis_runs(id) → prc_analysis_runs(id) ON DELETE NO ACTION @P3.8 |
| window_id | uuid | no | window_id uuid NOT NULL REFERENCES prc_analysis_windows(id) → prc_analysis_windows(id) ON DELETE NO ACTION @P3.8 |
| window_key | text | no | window_key text NOT NULL |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32) |
| subrange_key | bytea | no | subrange_key bytea NOT NULL CHECK(octet_length(subrange_key)=32) |
| output_ordinal | bigint | no | output_ordinal bigint NOT NULL CHECK(output_ordinal>=0) |
| output_ref | jsonb | no | output_ref jsonb NOT NULL |
| output_ref_schema_version | smallint | no | output_ref_schema_version smallint NOT NULL |
| output_hash | bytea | no | output_hash bytea NOT NULL CHECK(octet_length(output_hash)=32) |
| canonical_rows | jsonb | no | canonical_rows jsonb NOT NULL |
| canonical_rows_schema_version | smallint | no | canonical_rows_schema_version smallint NOT NULL |
| provenance | jsonb | no | provenance jsonb NOT NULL |
| provenance_schema_version | smallint | no | provenance_schema_version smallint NOT NULL |
| completed_at | timestamptz | no | completed_at timestamptz NOT NULL |

Constraints:
- `UNIQUE(run_id,window_key,recipe_hash,subrange_key,output_ordinal)`
- `CHECK (output_ref_schema_version > 0)`
- `CHECK (canonical_rows_schema_version > 0)`
- `CHECK (provenance_schema_version > 0)`

Indexes:

#### llm_provider_requests

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| logical_request_id | uuid | no | logical_request_id uuid PRIMARY KEY |
| logical_key | bytea | no | logical_key bytea NOT NULL UNIQUE CHECK(octet_length(logical_key)=32) |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32) |
| input_hash | bytea | no | input_hash bytea NOT NULL CHECK(octet_length(input_hash)=32) |
| purpose | text | no | purpose text NOT NULL |
| semantic_reask_ordinal | bigint | no | semantic_reask_ordinal bigint NOT NULL CHECK(semantic_reask_ordinal>=0) |
| replay_intent_id | uuid | no | replay_intent_id uuid NOT NULL |
| replay_of | uuid | yes | replay_of uuid NULL REFERENCES llm_provider_requests(logical_request_id) → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| command_id | uuid | yes | command_id uuid NULL UNIQUE |
| state | text | no | state text NOT NULL CHECK(state IN ('prepared','sending','completed','failed_known','unknown','abandoned_unknown')) |
| request_epoch | bigint | no | request_epoch bigint NOT NULL CHECK(request_epoch>=1) |
| idempotency_key | text | yes | idempotency_key text NULL |
| consumer_correlation_id | text | no | consumer_correlation_id text NOT NULL |
| reservation_amount | numeric(24,8) | no | reservation_amount numeric(24,8) NOT NULL CHECK(reservation_amount>=0) |

Constraints:

Indexes:

#### llm_budget_periods

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| account_id | text | no | account_id text NOT NULL |
| currency | text | no | currency text NOT NULL |
| period_start | timestamptz | no | period_start timestamptz NOT NULL |
| period_end | timestamptz | no | period_end timestamptz NOT NULL |
| cap | numeric(24,8) | no | cap numeric(24,8) NOT NULL |
| spent | numeric(24,8) | no | spent numeric(24,8) NOT NULL |
| reserved | numeric(24,8) | no | reserved numeric(24,8) NOT NULL |

Constraints:
- `CHECK(period_end>period_start)`
- `CHECK(spent>=0 AND reserved>=0 AND cap>=spent+reserved)`
- `UNIQUE(account_id,currency,period_start,period_end)`

Indexes:

#### llm_budget_reservations

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| operation_id | uuid | no | operation_id uuid NOT NULL UNIQUE → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.9 |
| period_id | uuid | no | period_id uuid NOT NULL REFERENCES llm_budget_periods(id) → llm_budget_periods(id) ON DELETE NO ACTION @P3.9 |
| upper_bound | numeric(24,8) | no | upper_bound numeric(24,8) NOT NULL CHECK(upper_bound>=0) |
| state | text | no | state text NOT NULL |
| settled_amount | numeric(24,8) | yes | settled_amount numeric(24,8) NULL CHECK(settled_amount>=0) |
| pricing_snapshot | jsonb | no | pricing_snapshot jsonb NOT NULL |
| pricing_snapshot_schema_version | smallint | no | pricing_snapshot_schema_version smallint NOT NULL |

Constraints:
- `CHECK (pricing_snapshot_schema_version > 0)`

Indexes:

#### llm_provider_calls

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| physical_call_id | uuid | no | physical_call_id uuid PRIMARY KEY |
| logical_request_id | uuid | no | logical_request_id uuid NOT NULL REFERENCES llm_provider_requests(logical_request_id) → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| ordinal | bigint | no | ordinal bigint NOT NULL CHECK(ordinal>=1) |
| provider_id | bigint | no | provider_id bigint NOT NULL REFERENCES llm_providers(id) → llm_providers(id) ON DELETE NO ACTION @P3.9 |
| account_id | text | no | account_id text NOT NULL |
| api_surface | text | no | api_surface text NOT NULL |
| model | text | no | model text NOT NULL |
| resolved_model_version | text | yes | resolved_model_version text NULL |
| request_epoch | bigint | no | request_epoch bigint NOT NULL CHECK(request_epoch>=1) |
| owner_attempt_id | uuid | no | owner_attempt_id uuid NOT NULL |
| owner_work_generation | bigint | no | owner_work_generation bigint NOT NULL CHECK(owner_work_generation>=1) |
| send_authorization_token | uuid | no | send_authorization_token uuid NOT NULL |
| send_claimed_at | timestamptz | yes | send_claimed_at timestamptz NULL |
| sent_at | timestamptz | yes | sent_at timestamptz NULL |
| response_at | timestamptz | yes | response_at timestamptz NULL |
| http_status | smallint | yes | http_status smallint NULL |
| finish_reason | text | yes | finish_reason text NULL |
| usage_status | text | no | usage_status text NOT NULL CHECK(usage_status IN ('known','unknown')) |
| usage | jsonb | yes | usage jsonb NULL |
| usage_schema_version | smallint | yes | usage_schema_version smallint NULL |
| cost_estimate | numeric(24,8) | no | cost_estimate numeric(24,8) NOT NULL CHECK(cost_estimate>=0) |
| cost_actual | numeric(24,8) | yes | cost_actual numeric(24,8) NULL CHECK(cost_actual>=0) |
| receipt_id | uuid | yes | receipt_id uuid NULL UNIQUE |
| receipt_state | text | no | receipt_state text NOT NULL |
| receipt_upload_key | text | no | receipt_upload_key text NOT NULL |
| receipt_disk | text | yes | receipt_disk text NULL |
| receipt_key | text | yes | receipt_key text NULL |
| receipt_hash | bytea | yes | receipt_hash bytea NULL CHECK(receipt_hash IS NULL OR octet_length(receipt_hash)=32) |
| receipt_schema_version | smallint | yes | receipt_schema_version smallint NULL |
| reservation_id | uuid | no | reservation_id uuid NOT NULL REFERENCES llm_budget_reservations(id) → llm_budget_reservations(id) ON DELETE NO ACTION @P3.9 |

Constraints:
- `UNIQUE(logical_request_id,ordinal)`
- `CHECK (usage_schema_version > 0)`
- `CHECK (receipt_schema_version > 0)`
- `CHECK ((usage IS NULL) = (usage_schema_version IS NULL))`

Indexes:

#### prc_analysis_llm_requests

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| analysis_run_id | uuid | no | analysis_run_id uuid NOT NULL REFERENCES prc_analysis_runs(id) → prc_analysis_runs(id) ON DELETE NO ACTION @P3.9 |
| work_id | uuid | no | work_id uuid NOT NULL REFERENCES prc_process_work(id) → prc_process_work(id) ON DELETE NO ACTION @P3.9 |
| attempt_id | uuid | no | attempt_id uuid NOT NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P3.9 |
| logical_request_id | uuid | no | logical_request_id uuid NOT NULL REFERENCES llm_provider_requests(logical_request_id) → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| physical_call_id | uuid | no | physical_call_id uuid NOT NULL UNIQUE REFERENCES llm_provider_calls(physical_call_id) → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.9 |
| expected_input_hash | bytea | no | expected_input_hash bytea NOT NULL CHECK(octet_length(expected_input_hash)=32) |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL CHECK(octet_length(recipe_hash)=32) |

Constraints:

Indexes:

#### llm_file_assets

Create: P3.12; activation boundary: P3.13; owner: `Services/Llm/Cache`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| provider_id | bigint | no | provider_id bigint NOT NULL REFERENCES llm_providers(id) → llm_providers(id) ON DELETE NO ACTION @P3.12 |
| account_id | text | no | account_id text NOT NULL |
| api_surface | text | no | api_surface text NOT NULL |
| content_hash | bytea | no | content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32) |
| mime | text | no | mime text NOT NULL |
| provider_uri | text | yes | provider_uri text NULL |
| state | text | no | state text NOT NULL |
| expires_at | timestamptz | yes | expires_at timestamptz NULL |
| creation_token | uuid | yes | creation_token uuid NULL |
| creation_lease_until | timestamptz | yes | creation_lease_until timestamptz NULL |

Constraints:
- `UNIQUE(provider_id,account_id,api_surface,content_hash,mime)`

Indexes:

#### llm_context_caches

Create: P3.12; activation boundary: P3.13; owner: `Services/Llm/Cache`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| provider_id | bigint | no | provider_id bigint NOT NULL REFERENCES llm_providers(id) → llm_providers(id) ON DELETE NO ACTION @P3.12 |
| account_id | text | no | account_id text NOT NULL |
| api_surface | text | no | api_surface text NOT NULL |
| model | text | no | model text NOT NULL |
| system_hash | bytea | no | system_hash bytea NOT NULL CHECK(octet_length(system_hash)=32) |
| schema_tool_policy_hash | bytea | no | schema_tool_policy_hash bytea NOT NULL CHECK(octet_length(schema_tool_policy_hash)=32) |
| representation_hash | bytea | no | representation_hash bytea NOT NULL CHECK(octet_length(representation_hash)=32) |
| content_ordering_hash | bytea | no | content_ordering_hash bytea NOT NULL CHECK(octet_length(content_ordering_hash)=32) |
| provider_name | text | yes | provider_name text NULL |
| expires_at | timestamptz | yes | expires_at timestamptz NULL |
| creation_state | text | no | creation_state text NOT NULL |
| creation_token | uuid | yes | creation_token uuid NULL |
| creation_lease_until | timestamptz | yes | creation_lease_until timestamptz NULL |
| cost | numeric(24,8) | no | cost numeric(24,8) NOT NULL CHECK(cost>=0) |

Constraints:
- `UNIQUE(provider_id,account_id,api_surface,model,system_hash,schema_tool_policy_hash,representation_hash,content_ordering_hash)`

Indexes:

#### prc_network_route_profile_members

Create: P2.1; activation boundary: P2.6; owner: `Repositories/Procurement/Network/RouteRepository`; retention: authority. Contract: platform-transport#s06; implementation-blueprint#bp-ddl-network.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| profile_id | uuid | no | profile_id uuid NOT NULL REFERENCES prc_network_route_profiles(id) → prc_network_route_profiles(id) ON DELETE NO ACTION @P2.1 |
| route_id | uuid | no | route_id uuid NOT NULL REFERENCES prc_network_routes(id) → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |

Constraints:
- `PRIMARY KEY(profile_id,route_id)`

Indexes:

### Changes to retained baseline tables

#### prc_procurements @P1.6

Initial subject head и durable cycle allocation под реальной procurement row.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| head_generation | bigint | no | head_generation bigint NOT NULL DEFAULT 1 CHECK(head_generation>=1) |
| detail_cycle_seq | bigint | no | detail_cycle_seq bigint NOT NULL DEFAULT 0 CHECK(detail_cycle_seq>=0) |
| status_cycle_seq | bigint | no | status_cycle_seq bigint NOT NULL DEFAULT 0 CHECK(status_cycle_seq>=0) |
| next_status_due_at | timestamptz | yes | next_status_due_at timestamptz NULL |

Remove at this cutover: none.

Constraints:

#### prc_documents @P1.6

Initial download/revalidation owner.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| head_generation | bigint | no | head_generation bigint NOT NULL DEFAULT 1 CHECK(head_generation>=1) |
| acquisition_cycle_seq | bigint | no | acquisition_cycle_seq bigint NOT NULL DEFAULT 0 CHECK(acquisition_cycle_seq>=0) |
| next_revalidation_due_at | timestamptz | yes | next_revalidation_due_at timestamptz NULL |

Remove at this cutover: none.

Constraints:

#### prc_procurements @P2.14

P2.14 atomic identity reader/writer/extension cutover; old registry_id+registry_number unique заменён. Opaque value не нормализуется, JSON attrs не authority lookup.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| platform_id | bigint | no | platform_id bigint NOT NULL REFERENCES prc_platforms(id) |
| source_identity_type | text | no | source_identity_type text NOT NULL COLLATE "C" |
| source_identity_value | text | no | source_identity_value text NOT NULL COLLATE "C" |

Remove at this cutover: registry_number, etp_number, ikz, registry_guid.

Constraints:
- `UNIQUE(platform_id,source_identity_type,source_identity_value)`
- `FOREIGN KEY(registry_id,platform_id) REFERENCES prc_registries(id,platform_id) ON DELETE NO ACTION`
- `CHECK(source_identity_type ~ '^[a-z][a-z0-9_.:-]{0,95}$')`
- `CHECK(char_length(source_identity_value) BETWEEN 1 AND 200)`

#### prc_registries @P2.14

Composite registry/platform FK target.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |

Remove at this cutover: none.

Constraints:
- `UNIQUE(id,platform_id)`

#### prc_eis_details @P2.14

Только EisExtensionPersister пишет extension в caller finalize tx.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| ikz | text | yes | ikz text NULL |
| etp_number | text | yes | etp_number text NULL |
| registry_guid | text | yes | registry_guid text NULL |

Remove at this cutover: none.

Constraints:
- `INDEX(ikz)`
- `INDEX(registry_guid)`

#### prc_documents @P2.14

Stable source key включает adapter scope; manual UUID namespace; signed URL rotation не меняет identity. Existing prc_documents сохраняется, pending acquisition intent до P3.5.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| platform_id | bigint | yes | platform_id bigint NULL REFERENCES prc_platforms(id) |
| source_document_key | text | no | source_document_key text NOT NULL |
| source_key_version | smallint | no | source_key_version smallint NOT NULL CHECK(source_key_version>0) |
| access_descriptor | jsonb | no | access_descriptor jsonb NOT NULL |
| access_descriptor_schema_version | smallint | no | access_descriptor_schema_version smallint NOT NULL CHECK(access_descriptor_schema_version>0) |
| access_descriptor_version | bigint | no | access_descriptor_version bigint NOT NULL DEFAULT 1 CHECK(access_descriptor_version>=1) |
| observed_metadata | jsonb | no | observed_metadata jsonb NOT NULL |
| observed_metadata_schema_version | smallint | no | observed_metadata_schema_version smallint NOT NULL CHECK(observed_metadata_schema_version>0) |

Remove at this cutover: none.

Constraints:
- `UNIQUE NULLS NOT DISTINCT(platform_id,source_document_key)`

#### prc_crawlers @P2.18

Profile schema/destructive rename вместе с последними acquisition consumers.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| listing_kind | text | no | listing_kind text NOT NULL CHECK(listing_kind IN ('fresh','archive','delta')) |
| route_profile_id | uuid | yes | route_profile_id uuid NULL REFERENCES prc_network_route_profiles(id) |
| capability_selection | jsonb | no | capability_selection jsonb NOT NULL |
| capability_selection_schema_version | smallint | no | capability_selection_schema_version smallint NOT NULL CHECK(capability_selection_schema_version>0) |

Remove at this cutover: mode, source_method, fallback_method, proxy_country, proxy_mode, execution_lane, stop_policy.

Constraints:

#### prc_proxies @P2.6

Sole health/control owner становится Network, все transport callers и reactivate переключены P2.6.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| route_id | uuid | no | route_id uuid NOT NULL REFERENCES prc_network_routes(id) |

Remove at this cutover: open_until, open_count, last_failure_reason, last_success_at, last_failure_at, fails_count, is_active, disabled_at, disabled_reason.

Constraints:
- `UNIQUE(route_id)`

#### prc_procurements @P2.18

Status/fetched fields — projection; authoritative scheduler только Process work/intents.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |

Remove at this cutover: detail_attempts, detail_last_error, detail_next_retry_at, detail_failed.

Constraints:

#### prc_documents @P3.1

Pointer changes under document head fence.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| current_version_id | bigint | yes | current_version_id bigint NULL REFERENCES prc_document_versions(id) |

Remove at this cutover: none.

Constraints:

#### prc_document_versions @P3.1

A→B→A = три revisions и два blobs. Existing bigint PK/document/parent FK сохраняются; lineage и source validators не execution authority.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| revision_no | bigint | no | revision_no bigint NOT NULL CHECK(revision_no>=1) |
| blob_id | uuid | no | blob_id uuid NOT NULL REFERENCES prc_document_blobs(id) |
| content_hash | bytea | no | content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32) |
| etag | text | yes | etag text NULL |
| last_modified | timestamptz | yes | last_modified timestamptz NULL |
| first_seen_at | timestamptz | no | first_seen_at timestamptz NOT NULL |
| normalized_entry_path | text | yes | normalized_entry_path text NULL |
| child_ordinal | bigint | yes | child_ordinal bigint NULL CHECK(child_ordinal>=0) |

Remove at this cutover: none.

Constraints:
- `UNIQUE(document_id,revision_no)`
- `UNIQUE(parent_version_id,normalized_entry_path)`
- `DROP UNIQUE(document_id,content_hash)`

#### prc_document_representations @P3.1

Existing bigint PK; explicit immutable disk/key/hash/range and complete source page coverage.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| disk | text | no | disk text NOT NULL |
| preparation_recipe_hash | bytea | no | preparation_recipe_hash bytea NOT NULL CHECK(octet_length(preparation_recipe_hash)=32) |
| content_hash | bytea | no | content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32) |
| range_key | text | no | range_key text NOT NULL |
| tool_version | text | no | tool_version text NOT NULL |
| coverage_manifest | jsonb | no | coverage_manifest jsonb NOT NULL |
| coverage_manifest_schema_version | smallint | no | coverage_manifest_schema_version smallint NOT NULL CHECK(coverage_manifest_schema_version>0) |

Remove at this cutover: none.

Constraints:
- `UNIQUE(document_version_id,preparation_recipe_hash,kind,range_key)`

#### prc_documents @P3.5

Canonical procurement links уже в prc_procurement_documents; old execution fields удаляются с document pipeline cutover. Display status — projection.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |

Remove at this cutover: procurement_id, external_id, attempts, last_error, last_error_at, next_retry_at, failed_permanently, locked_by, locked_until.

Constraints:

#### prc_document_versions @P3.8

Создание нового run увеличивает analysis_generation под version row lock, immutable bytes не меняются.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| analysis_generation | bigint | no | analysis_generation bigint NOT NULL DEFAULT 1 CHECK(analysis_generation>=1) |
| current_analysis_run_id | uuid | yes | current_analysis_run_id uuid NULL REFERENCES prc_analysis_runs(id) |

Remove at this cutover: none.

Constraints:

#### prc_ai_analyses @P3.13

Result/review/correction row и bigint PK остаются; result/review_status/reviewed_by/reviewed_at/operator_comment/parent_analysis_id/provenance сохраняются. Execution/recipe/cache authority в work/analysis/LLM. Точный surviving FK map owning P3.8–P3.14 проверяет до swap; legacy model не rebind до P3.13.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| analysis_run_id | uuid | yes | analysis_run_id uuid NULL REFERENCES prc_analysis_runs(id) |

Remove at this cutover: locked_by, locked_until, attempts, last_error, last_error_at, next_retry_at, failed_permanently, cache_id, cache_expires_at, rules_hash, progress.

Constraints:

#### prc_raw_artifacts @P1.14

Двухфазная retention, pending/pinned/ref gate; object I/O вне tx.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| deletion_generation | bigint | no | deletion_generation bigint NOT NULL DEFAULT 0 CHECK(deletion_generation>=0) |
| deletion_marked_at | timestamptz | yes | deletion_marked_at timestamptz NULL |

Remove at this cutover: none.

Constraints:

### Installed baseline (before Process ledger)

#### az_direct_grants

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| grantable_type | character varying / varchar | NO | character varying(255) NOT NULL |
| grantable_id | bigint / int8 | NO | bigint NOT NULL |
| permission_key | character varying / varchar | NO | character varying(255) NOT NULL |
| panel_id | character varying / varchar | NO | character varying(128) NOT NULL |
| expires_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `az_direct_grant_unique`: `UNIQUE (grantable_type, grantable_id, permission_key, panel_id)`
- `az_direct_grants_pkey`: `PRIMARY KEY (id)`

Indexes:
- `az_direct_grant_lookup`: `CREATE INDEX az_direct_grant_lookup ON public.az_direct_grants USING btree (grantable_type, grantable_id, panel_id, expires_at)`
- `az_direct_grant_unique`: `CREATE UNIQUE INDEX az_direct_grant_unique ON public.az_direct_grants USING btree (grantable_type, grantable_id, permission_key, panel_id)`
- `az_direct_grants_pkey`: `CREATE UNIQUE INDEX az_direct_grants_pkey ON public.az_direct_grants USING btree (id)`
- `azg_853fadc7b32c436284043003bb137233bf92ae6a_idx`: `CREATE INDEX azg_853fadc7b32c436284043003bb137233bf92ae6a_idx ON public.az_direct_grants USING btree (grantable_type, grantable_id)`

#### az_guard_role_permissions

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| role_id | bigint / int8 | NO | bigint NOT NULL |
| permission_key | character varying / varchar | NO | character varying(255) NOT NULL |
| panel_id | character varying / varchar | NO | character varying(128) NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `az_guard_role_permissions_pkey`: `PRIMARY KEY (id)`
- `az_guard_role_permissions_role_id_foreign`: `FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE`
- `az_role_perm_unique`: `UNIQUE (role_id, permission_key, panel_id)`

Indexes:
- `az_guard_role_permissions_pkey`: `CREATE UNIQUE INDEX az_guard_role_permissions_pkey ON public.az_guard_role_permissions USING btree (id)`
- `az_role_perm_lookup`: `CREATE INDEX az_role_perm_lookup ON public.az_guard_role_permissions USING btree (panel_id, permission_key)`
- `az_role_perm_unique`: `CREATE UNIQUE INDEX az_role_perm_unique ON public.az_guard_role_permissions USING btree (role_id, permission_key, panel_id)`

#### cache

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| key | character varying / varchar | NO | character varying(255) NOT NULL |
| value | text / text | NO | text NOT NULL |
| expiration | integer / int4 | NO | integer NOT NULL |

Constraints:
- `cache_pkey`: `PRIMARY KEY (key)`

Indexes:
- `cache_expiration_index`: `CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration)`
- `cache_pkey`: `CREATE UNIQUE INDEX cache_pkey ON public.cache USING btree (key)`

#### cache_locks

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| key | character varying / varchar | NO | character varying(255) NOT NULL |
| owner | character varying / varchar | NO | character varying(255) NOT NULL |
| expiration | integer / int4 | NO | integer NOT NULL |

Constraints:
- `cache_locks_pkey`: `PRIMARY KEY (key)`

Indexes:
- `cache_locks_expiration_index`: `CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration)`
- `cache_locks_pkey`: `CREATE UNIQUE INDEX cache_locks_pkey ON public.cache_locks USING btree (key)`

#### failed_jobs

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | character varying / varchar | NO | character varying(255) NOT NULL |
| connection | text / text | NO | text NOT NULL |
| queue | text / text | NO | text NOT NULL |
| payload | text / text | NO | text NOT NULL |
| exception | text / text | NO | text NOT NULL |
| failed_at | timestamp without time zone / timestamp | NO | timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL |

Constraints:
- `failed_jobs_pkey`: `PRIMARY KEY (id)`
- `failed_jobs_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `failed_jobs_pkey`: `CREATE UNIQUE INDEX failed_jobs_pkey ON public.failed_jobs USING btree (id)`
- `failed_jobs_uuid_unique`: `CREATE UNIQUE INDEX failed_jobs_uuid_unique ON public.failed_jobs USING btree (uuid)`

#### job_batches

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | character varying / varchar | NO | character varying(255) NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| total_jobs | integer / int4 | NO | integer NOT NULL |
| pending_jobs | integer / int4 | NO | integer NOT NULL |
| failed_jobs | integer / int4 | NO | integer NOT NULL |
| failed_job_ids | text / text | NO | text NOT NULL |
| options | text / text | YES | text |
| cancelled_at | integer / int4 | YES | integer |
| created_at | integer / int4 | NO | integer NOT NULL |
| finished_at | integer / int4 | YES | integer |

Constraints:
- `job_batches_pkey`: `PRIMARY KEY (id)`

Indexes:
- `job_batches_pkey`: `CREATE UNIQUE INDEX job_batches_pkey ON public.job_batches USING btree (id)`

#### jobs

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| queue | character varying / varchar | NO | character varying(255) NOT NULL |
| payload | text / text | NO | text NOT NULL |
| attempts | smallint / int2 | NO | smallint NOT NULL |
| reserved_at | integer / int4 | YES | integer |
| available_at | integer / int4 | NO | integer NOT NULL |
| created_at | integer / int4 | NO | integer NOT NULL |

Constraints:
- `jobs_pkey`: `PRIMARY KEY (id)`

Indexes:
- `jobs_pkey`: `CREATE UNIQUE INDEX jobs_pkey ON public.jobs USING btree (id)`
- `jobs_queue_index`: `CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue)`

#### llm_calls

Owner: Llm; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| analysis_id | bigint / int8 | YES | bigint |
| analysis_run_id | bigint / int8 | YES | bigint |
| purpose | text / text | NO | text NOT NULL |
| provider | text / text | NO | text NOT NULL |
| model | text / text | NO | text NOT NULL |
| prompt_version_set | jsonb / jsonb | YES | jsonb |
| cache_id | text / text | YES | text |
| request_ref | text / text | YES | text |
| response_ref | text / text | YES | text |
| prompt_tokens | integer / int4 | YES | integer |
| completion_tokens | integer / int4 | YES | integer |
| cached_tokens | integer / int4 | YES | integer |
| total_tokens | integer / int4 | YES | integer |
| cost_micros | bigint / int8 | YES | bigint |
| success | boolean / bool | NO | boolean NOT NULL |
| error | text / text | YES | text |
| duration_ms | integer / int4 | YES | integer |
| called_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| created_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `llm_calls_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id)`
- `llm_calls_analysis_run_id_foreign`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_ai_analysis_runs(id)`
- `llm_calls_pkey`: `PRIMARY KEY (id, called_at)`

Indexes:
- `llm_calls_analysis_id_index`: `CREATE INDEX llm_calls_analysis_id_index ON ONLY public.llm_calls USING btree (analysis_id)`
- `llm_calls_analysis_run_id_index`: `CREATE INDEX llm_calls_analysis_run_id_index ON ONLY public.llm_calls USING btree (analysis_run_id)`
- `llm_calls_pkey`: `CREATE UNIQUE INDEX llm_calls_pkey ON ONLY public.llm_calls USING btree (id, called_at)`
- `llm_calls_purpose_called_at_index`: `CREATE INDEX llm_calls_purpose_called_at_index ON ONLY public.llm_calls USING btree (purpose, called_at)`

#### llm_prompts

Owner: Llm; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_type | text / text | NO | text NOT NULL |
| key | text / text | NO | text NOT NULL |
| step | text / text | NO | text NOT NULL |
| content | text / text | NO | text NOT NULL |
| version | integer / int4 | NO | integer NOT NULL |
| priority | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| is_protected | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `llm_prompts_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text, 'any'::text])))`
- `llm_prompts_document_type_key_version_unique`: `UNIQUE (document_type, key, version)`
- `llm_prompts_pkey`: `PRIMARY KEY (id)`

Indexes:
- `llm_prompts_document_type_key_version_unique`: `CREATE UNIQUE INDEX llm_prompts_document_type_key_version_unique ON public.llm_prompts USING btree (document_type, key, version)`
- `llm_prompts_pkey`: `CREATE UNIQUE INDEX llm_prompts_pkey ON public.llm_prompts USING btree (id)`

#### llm_providers

Owner: Llm; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | text / text | NO | text NOT NULL |
| driver | text / text | NO | text NOT NULL |
| base_url | text / text | YES | text |
| options | jsonb / jsonb | YES | jsonb |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `llm_providers_code_unique`: `UNIQUE (code)`
- `llm_providers_pkey`: `PRIMARY KEY (id)`

Indexes:
- `llm_providers_code_unique`: `CREATE UNIQUE INDEX llm_providers_code_unique ON public.llm_providers USING btree (code)`
- `llm_providers_pkey`: `CREATE UNIQUE INDEX llm_providers_pkey ON public.llm_providers USING btree (id)`

#### llm_routes

Owner: Llm; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_type | text / text | NO | text NOT NULL |
| purpose | text / text | NO | text NOT NULL |
| provider_id | bigint / int8 | NO | bigint NOT NULL |
| model | text / text | NO | text NOT NULL |
| options | jsonb / jsonb | YES | jsonb |
| priority | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| shadow | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `llm_routes_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text, 'any'::text])))`
- `llm_routes_document_type_purpose_priority_shadow_unique`: `UNIQUE (document_type, purpose, priority, shadow)`
- `llm_routes_pkey`: `PRIMARY KEY (id)`
- `llm_routes_provider_id_foreign`: `FOREIGN KEY (provider_id) REFERENCES llm_providers(id)`

Indexes:
- `llm_routes_document_type_purpose_priority_shadow_unique`: `CREATE UNIQUE INDEX llm_routes_document_type_purpose_priority_shadow_unique ON public.llm_routes USING btree (document_type, purpose, priority, shadow)`
- `llm_routes_pkey`: `CREATE UNIQUE INDEX llm_routes_pkey ON public.llm_routes USING btree (id)`
- `llm_routes_provider_id_index`: `CREATE INDEX llm_routes_provider_id_index ON public.llm_routes USING btree (provider_id)`

#### migrations

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | integer / int4 | NO | integer NOT NULL |
| migration | character varying / varchar | NO | character varying(255) NOT NULL |
| batch | integer / int4 | NO | integer NOT NULL |

Constraints:
- `migrations_pkey`: `PRIMARY KEY (id)`

Indexes:
- `migrations_pkey`: `CREATE UNIQUE INDEX migrations_pkey ON public.migrations USING btree (id)`

#### model_has_roles

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| role_id | bigint / int8 | NO | bigint NOT NULL |
| model_type | character varying / varchar | NO | character varying(255) NOT NULL |
| model_id | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `model_has_roles_role_id_foreign`: `FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE`
- `model_has_roles_unique`: `UNIQUE (role_id, model_type, model_id)`

Indexes:
- `azg_ecf0cb43c2fcb2e068a1502f15dbf41f2077daa4_idx`: `CREATE INDEX azg_ecf0cb43c2fcb2e068a1502f15dbf41f2077daa4_idx ON public.model_has_roles USING btree (model_type, model_id)`
- `model_has_roles_unique`: `CREATE UNIQUE INDEX model_has_roles_unique ON public.model_has_roles USING btree (role_id, model_type, model_id)`

#### model_has_scopes

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| model_type | character varying / varchar | NO | character varying(255) NOT NULL |
| model_id | bigint / int8 | NO | bigint NOT NULL |
| scope_entity_type | character varying / varchar | YES | character varying(255) |
| scope_entity_id | bigint / int8 | YES | bigint |
| scope_class | character varying / varchar | YES | character varying(255) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| role_id | bigint / int8 | YES | bigint |
| panel_id | character varying / varchar | YES | character varying(255) |

Constraints:
- `model_has_scopes_pkey`: `PRIMARY KEY (id)`
- `model_has_scopes_role_id_foreign`: `FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL`

Indexes:
- `azg_364be6041f25cf9392be1970c7177ba6390b9198_idx`: `CREATE INDEX azg_364be6041f25cf9392be1970c7177ba6390b9198_idx ON public.model_has_scopes USING btree (model_type, model_id)`
- `azg_36c1e6e07cca0dccc8ef9a39c8148f57a212b63f_idx`: `CREATE INDEX azg_36c1e6e07cca0dccc8ef9a39c8148f57a212b63f_idx ON public.model_has_scopes USING btree (scope_entity_type, scope_entity_id)`
- `model_has_scopes_panel_id_index`: `CREATE INDEX model_has_scopes_panel_id_index ON public.model_has_scopes USING btree (panel_id)`
- `model_has_scopes_pkey`: `CREATE UNIQUE INDEX model_has_scopes_pkey ON public.model_has_scopes USING btree (id)`
- `model_has_scopes_unique`: `CREATE UNIQUE INDEX model_has_scopes_unique ON public.model_has_scopes USING btree (model_type, model_id, COALESCE(scope_entity_type, ''::character varying), COALESCE(scope_entity_id, (0)::bigint), COALESCE(role_id, (0)::bigint), COALESCE(panel_id, ''::character varying))`

#### org_organization_identifiers

Owner: Organization; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| organization_id | bigint / int8 | NO | bigint NOT NULL |
| platform_code | character varying / varchar | NO | character varying(64) NOT NULL |
| type | character varying / varchar | NO | character varying(64) NOT NULL |
| value | character varying / varchar | NO | character varying(255) NOT NULL |

Constraints:
- `org_identifier_identity_unique`: `UNIQUE (platform_code, type, value)`
- `org_identifier_owner_type_unique`: `UNIQUE (organization_id, platform_code, type)`
- `org_organization_identifiers_organization_id_foreign`: `FOREIGN KEY (organization_id) REFERENCES org_organizations(id) ON DELETE CASCADE`
- `org_organization_identifiers_pkey`: `PRIMARY KEY (id)`

Indexes:
- `org_identifier_identity_unique`: `CREATE UNIQUE INDEX org_identifier_identity_unique ON public.org_organization_identifiers USING btree (platform_code, type, value)`
- `org_identifier_owner_type_unique`: `CREATE UNIQUE INDEX org_identifier_owner_type_unique ON public.org_organization_identifiers USING btree (organization_id, platform_code, type)`
- `org_organization_identifiers_pkey`: `CREATE UNIQUE INDEX org_organization_identifiers_pkey ON public.org_organization_identifiers USING btree (id)`

#### org_organizations

Owner: Organization; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| inn | character varying / varchar | YES | character varying(255) |
| kpp | character varying / varchar | YES | character varying(255) |
| ogrn | character varying / varchar | YES | character varying(255) |
| full_name | character varying / varchar | NO | character varying(255) NOT NULL |
| short_name | character varying / varchar | YES | character varying(255) |
| address_raw | text / text | YES | text |
| region_code | character varying / varchar | YES | character varying(255) |
| phone | character varying / varchar | YES | character varying(255) |
| email | character varying / varchar | YES | character varying(255) |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `org_organizations_pkey`: `PRIMARY KEY (id)`
- `org_organizations_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `org_organizations_inn_index`: `CREATE INDEX org_organizations_inn_index ON public.org_organizations USING btree (inn)`
- `org_organizations_inn_kpp_unique`: `CREATE UNIQUE INDEX org_organizations_inn_kpp_unique ON public.org_organizations USING btree (inn, kpp) NULLS NOT DISTINCT WHERE (inn IS NOT NULL)`
- `org_organizations_pkey`: `CREATE UNIQUE INDEX org_organizations_pkey ON public.org_organizations USING btree (id)`
- `org_organizations_region_code_index`: `CREATE INDEX org_organizations_region_code_index ON public.org_organizations USING btree (region_code)`
- `org_organizations_uuid_unique`: `CREATE UNIQUE INDEX org_organizations_uuid_unique ON public.org_organizations USING btree (uuid)`

#### password_reset_tokens

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| email | character varying / varchar | NO | character varying(255) NOT NULL |
| token | character varying / varchar | NO | character varying(255) NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `password_reset_tokens_pkey`: `PRIMARY KEY (email)`

Indexes:
- `password_reset_tokens_pkey`: `CREATE UNIQUE INDEX password_reset_tokens_pkey ON public.password_reset_tokens USING btree (email)`

#### prc_ai_analyses

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| analysis_run_id | bigint / int8 | NO | bigint NOT NULL |
| step | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| document_type | text / text | YES | text |
| result | jsonb / jsonb | YES | jsonb |
| provider | text / text | YES | text |
| model | text / text | YES | text |
| prompt_tokens | integer / int4 | YES | integer |
| completion_tokens | integer / int4 | YES | integer |
| cached_tokens | integer / int4 | YES | integer |
| total_tokens | integer / int4 | YES | integer |
| cost_micros | bigint / int8 | YES | bigint |
| locked_by | text / text | YES | text |
| locked_until | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| attempts | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| last_error | text / text | YES | text |
| last_error_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| next_retry_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| failed_permanently | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| started_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| cache_id | text / text | YES | text |
| cache_expires_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| rules_hash | text / text | YES | text |
| progress | jsonb / jsonb | YES | jsonb |
| review_status | text / text | NO | text DEFAULT 'unreviewed'::text NOT NULL |
| reviewed_by | bigint / int8 | YES | bigint |
| reviewed_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| operator_comment | text / text | YES | text |
| parent_analysis_id | bigint / int8 | YES | bigint |
| superseded_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| is_shadow | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_analyses_analysis_run_id_foreign`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_ai_analysis_runs(id)`
- `prc_ai_analyses_document_type_check`: `CHECK (((document_type IS NULL) OR (document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text]))))`
- `prc_ai_analyses_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_ai_analyses_parent_analysis_id_foreign`: `FOREIGN KEY (parent_analysis_id) REFERENCES prc_ai_analyses(id) ON DELETE SET NULL`
- `prc_ai_analyses_pkey`: `PRIMARY KEY (id)`
- `prc_ai_analyses_reviewed_by_foreign`: `FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL`
- `prc_ai_analyses_screening_result_schema_check`: `CHECK (((step <> 'screening'::text) OR (status <> 'succeeded'::text) OR (superseded_at IS NOT NULL) OR (is_shadow = true) OR ((result IS NOT NULL) AND COALESCE(((result ->> 'schema_version'::text) = '2'::text), false) AND COALESCE((jsonb_typeof((result -> 'screening'::text)) = 'object'::text), false) AND COALESCE((jsonb_typeof((result -> 'decision'::text)) = 'object'::text), false))))`
- `prc_ai_analyses_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_ai_analyses_active_screening_cardinality_index`: `CREATE INDEX prc_ai_analyses_active_screening_cardinality_index ON public.prc_ai_analyses USING btree ((((result -> 'screening'::text) ->> 'estimate_cardinality'::text))) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_screening_disposition_index`: `CREATE INDEX prc_ai_analyses_active_screening_disposition_index ON public.prc_ai_analyses USING btree ((((result -> 'decision'::text) ->> 'disposition'::text))) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_screening_reason_index`: `CREATE INDEX prc_ai_analyses_active_screening_reason_index ON public.prc_ai_analyses USING btree ((((result -> 'decision'::text) ->> 'reason'::text))) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_screening_summary_search_index`: `CREATE INDEX prc_ai_analyses_active_screening_summary_search_index ON public.prc_ai_analyses USING gin (to_tsvector('russian'::regconfig, COALESCE((result #>> '{screening,summary}'::text[]), ''::text))) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_screening_tags_gin_index`: `CREATE INDEX prc_ai_analyses_active_screening_tags_gin_index ON public.prc_ai_analyses USING gin (((result #> '{screening,tags}'::text[]))) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_screening_type_index`: `CREATE INDEX prc_ai_analyses_active_screening_type_index ON public.prc_ai_analyses USING btree (document_type) WHERE ((step = 'screening'::text) AND (superseded_at IS NULL) AND (is_shadow = false) AND ((result ->> 'schema_version'::text) = '2'::text))`
- `prc_ai_analyses_active_version_step_unique`: `CREATE UNIQUE INDEX prc_ai_analyses_active_version_step_unique ON public.prc_ai_analyses USING btree (document_version_id, step) WHERE ((superseded_at IS NULL) AND (is_shadow = false))`
- `prc_ai_analyses_analysis_run_id_index`: `CREATE INDEX prc_ai_analyses_analysis_run_id_index ON public.prc_ai_analyses USING btree (analysis_run_id)`
- `prc_ai_analyses_document_version_id_index`: `CREATE INDEX prc_ai_analyses_document_version_id_index ON public.prc_ai_analyses USING btree (document_version_id)`
- `prc_ai_analyses_parent_analysis_id_index`: `CREATE INDEX prc_ai_analyses_parent_analysis_id_index ON public.prc_ai_analyses USING btree (parent_analysis_id)`
- `prc_ai_analyses_pkey`: `CREATE UNIQUE INDEX prc_ai_analyses_pkey ON public.prc_ai_analyses USING btree (id)`
- `prc_ai_analyses_result_branch_reason_index`: `CREATE INDEX prc_ai_analyses_result_branch_reason_index ON public.prc_ai_analyses USING btree (((result ->> 'branch_reason'::text)))`
- `prc_ai_analyses_result_needs_review_index`: `CREATE INDEX prc_ai_analyses_result_needs_review_index ON public.prc_ai_analyses USING btree ((((result ->> 'needs_review'::text))::boolean))`
- `prc_ai_analyses_reviewed_by_index`: `CREATE INDEX prc_ai_analyses_reviewed_by_index ON public.prc_ai_analyses USING btree (reviewed_by)`
- `prc_ai_analyses_uuid_unique`: `CREATE UNIQUE INDEX prc_ai_analyses_uuid_unique ON public.prc_ai_analyses USING btree (uuid)`

#### prc_ai_analysis_runs

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| mode | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| prompt_tokens | integer / int4 | YES | integer |
| completion_tokens | integer / int4 | YES | integer |
| cached_tokens | integer / int4 | YES | integer |
| total_tokens | integer / int4 | YES | integer |
| cost_micros | bigint / int8 | YES | bigint |
| initiated_by | bigint / int8 | YES | bigint |
| started_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_analysis_runs_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_ai_analysis_runs_initiated_by_foreign`: `FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL`
- `prc_ai_analysis_runs_mode_check`: `CHECK ((mode = ANY (ARRAY['auto'::text, 'manual'::text])))`
- `prc_ai_analysis_runs_pkey`: `PRIMARY KEY (id)`
- `prc_ai_analysis_runs_status_check`: `CHECK ((status = ANY (ARRAY['pending'::text, 'running'::text, 'succeeded'::text, 'failed'::text])))`
- `prc_ai_analysis_runs_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_ai_analysis_runs_active_version_unique`: `CREATE UNIQUE INDEX prc_ai_analysis_runs_active_version_unique ON public.prc_ai_analysis_runs USING btree (document_version_id) WHERE (finished_at IS NULL)`
- `prc_ai_analysis_runs_document_version_id_index`: `CREATE INDEX prc_ai_analysis_runs_document_version_id_index ON public.prc_ai_analysis_runs USING btree (document_version_id)`
- `prc_ai_analysis_runs_initiated_by_index`: `CREATE INDEX prc_ai_analysis_runs_initiated_by_index ON public.prc_ai_analysis_runs USING btree (initiated_by)`
- `prc_ai_analysis_runs_pkey`: `CREATE UNIQUE INDEX prc_ai_analysis_runs_pkey ON public.prc_ai_analysis_runs USING btree (id)`
- `prc_ai_analysis_runs_uuid_unique`: `CREATE UNIQUE INDEX prc_ai_analysis_runs_uuid_unique ON public.prc_ai_analysis_runs USING btree (uuid)`

#### prc_ai_corrections

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| analysis_id | bigint / int8 | NO | bigint NOT NULL |
| estimate_id | bigint / int8 | YES | bigint |
| document_type | text / text | NO | text NOT NULL |
| field_path | text / text | YES | text |
| reason | text / text | NO | text NOT NULL |
| content | text / text | NO | text NOT NULL |
| hint | text / text | YES | text |
| is_reusable | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| created_by | bigint / int8 | NO | bigint NOT NULL |
| applied_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_corrections_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id) ON DELETE CASCADE`
- `prc_ai_corrections_created_by_foreign`: `FOREIGN KEY (created_by) REFERENCES users(id)`
- `prc_ai_corrections_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text])))`
- `prc_ai_corrections_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE SET NULL`
- `prc_ai_corrections_pkey`: `PRIMARY KEY (id)`
- `prc_ai_corrections_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_ai_corrections_analysis_id_index`: `CREATE INDEX prc_ai_corrections_analysis_id_index ON public.prc_ai_corrections USING btree (analysis_id)`
- `prc_ai_corrections_created_by_index`: `CREATE INDEX prc_ai_corrections_created_by_index ON public.prc_ai_corrections USING btree (created_by)`
- `prc_ai_corrections_estimate_id_index`: `CREATE INDEX prc_ai_corrections_estimate_id_index ON public.prc_ai_corrections USING btree (estimate_id)`
- `prc_ai_corrections_pkey`: `CREATE UNIQUE INDEX prc_ai_corrections_pkey ON public.prc_ai_corrections USING btree (id)`
- `prc_ai_corrections_uuid_unique`: `CREATE UNIQUE INDEX prc_ai_corrections_uuid_unique ON public.prc_ai_corrections USING btree (uuid)`

#### prc_ai_dataset_examples

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| fixture_id | bigint / int8 | YES | bigint |
| analysis_id | bigint / int8 | YES | bigint |
| document_type | text / text | NO | text NOT NULL |
| task | text / text | NO | text NOT NULL |
| gold | jsonb / jsonb | YES | jsonb |
| gold_ref | text / text | YES | text |
| source | text / text | NO | text NOT NULL |
| verified_by | bigint / int8 | YES | bigint |
| verified_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| version | integer / int4 | NO | integer DEFAULT 1 NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_dataset_examples_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id) ON DELETE SET NULL`
- `prc_ai_dataset_examples_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text])))`
- `prc_ai_dataset_examples_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_ai_dataset_examples_fixture_id_foreign`: `FOREIGN KEY (fixture_id) REFERENCES prc_ai_fixtures(id) ON DELETE SET NULL`
- `prc_ai_dataset_examples_pkey`: `PRIMARY KEY (id)`
- `prc_ai_dataset_examples_task_check`: `CHECK ((task = ANY (ARRAY['screening'::text, 'extraction'::text])))`
- `prc_ai_dataset_examples_verified_by_foreign`: `FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL`

Indexes:
- `prc_ai_dataset_examples_analysis_id_index`: `CREATE INDEX prc_ai_dataset_examples_analysis_id_index ON public.prc_ai_dataset_examples USING btree (analysis_id)`
- `prc_ai_dataset_examples_document_version_id_index`: `CREATE INDEX prc_ai_dataset_examples_document_version_id_index ON public.prc_ai_dataset_examples USING btree (document_version_id)`
- `prc_ai_dataset_examples_fixture_id_index`: `CREATE INDEX prc_ai_dataset_examples_fixture_id_index ON public.prc_ai_dataset_examples USING btree (fixture_id)`
- `prc_ai_dataset_examples_pkey`: `CREATE UNIQUE INDEX prc_ai_dataset_examples_pkey ON public.prc_ai_dataset_examples USING btree (id)`
- `prc_ai_dataset_examples_verified_by_index`: `CREATE INDEX prc_ai_dataset_examples_verified_by_index ON public.prc_ai_dataset_examples USING btree (verified_by)`

#### prc_ai_eval_results

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| eval_run_id | bigint / int8 | NO | bigint NOT NULL |
| dataset_example_id | bigint / int8 | NO | bigint NOT NULL |
| metric | text / text | NO | text NOT NULL |
| value | numeric / numeric | NO | numeric(18,6) NOT NULL |
| details | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_eval_results_dataset_example_id_foreign`: `FOREIGN KEY (dataset_example_id) REFERENCES prc_ai_dataset_examples(id)`
- `prc_ai_eval_results_eval_run_id_foreign`: `FOREIGN KEY (eval_run_id) REFERENCES prc_ai_eval_runs(id)`
- `prc_ai_eval_results_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_ai_eval_results_dataset_example_id_index`: `CREATE INDEX prc_ai_eval_results_dataset_example_id_index ON public.prc_ai_eval_results USING btree (dataset_example_id)`
- `prc_ai_eval_results_eval_run_id_index`: `CREATE INDEX prc_ai_eval_results_eval_run_id_index ON public.prc_ai_eval_results USING btree (eval_run_id)`
- `prc_ai_eval_results_pkey`: `CREATE UNIQUE INDEX prc_ai_eval_results_pkey ON public.prc_ai_eval_results USING btree (id)`

#### prc_ai_eval_runs

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_type | text / text | NO | text NOT NULL |
| task | text / text | NO | text NOT NULL |
| dataset_version | text / text | NO | text NOT NULL |
| provider | text / text | NO | text NOT NULL |
| model | text / text | NO | text NOT NULL |
| prompt_version_set | jsonb / jsonb | YES | jsonb |
| metrics | jsonb / jsonb | YES | jsonb |
| ci_status | text / text | NO | text NOT NULL |
| started_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_eval_runs_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text, 'any'::text])))`
- `prc_ai_eval_runs_pkey`: `PRIMARY KEY (id)`
- `prc_ai_eval_runs_task_check`: `CHECK ((task = ANY (ARRAY['screening'::text, 'extraction'::text])))`

Indexes:
- `prc_ai_eval_runs_pkey`: `CREATE UNIQUE INDEX prc_ai_eval_runs_pkey ON public.prc_ai_eval_runs USING btree (id)`

#### prc_ai_fixtures

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | text / text | NO | text NOT NULL |
| source_format | text / text | NO | text NOT NULL |
| storage_path | text / text | NO | text NOT NULL |
| disk | text / text | YES | text |
| sha256 | text / text | NO | text NOT NULL |
| size_bytes | bigint / int8 | NO | bigint NOT NULL |
| mime | text / text | NO | text NOT NULL |
| original_filename | text / text | NO | text NOT NULL |
| source_path | text / text | NO | text NOT NULL |
| generator | text / text | YES | text |
| cluster_id | text / text | YES | text |
| document_type | text / text | NO | text NOT NULL |
| owner_document_type | text / text | NO | text NOT NULL |
| title | text / text | NO | text NOT NULL |
| quirks | jsonb / jsonb | NO | jsonb DEFAULT '[]'::jsonb NOT NULL |
| type_profile | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| is_verified | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| document_id | bigint / int8 | YES | bigint |
| verified_by | bigint / int8 | YES | bigint |
| verified_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| notes | text / text | YES | text |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_fixtures_code_unique`: `UNIQUE (code)`
- `prc_ai_fixtures_document_id_foreign`: `FOREIGN KEY (document_id) REFERENCES prc_documents(id) ON DELETE SET NULL`
- `prc_ai_fixtures_document_id_unique`: `UNIQUE (document_id)`
- `prc_ai_fixtures_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text])))`
- `prc_ai_fixtures_owner_document_type_check`: `CHECK ((owner_document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text])))`
- `prc_ai_fixtures_pkey`: `PRIMARY KEY (id)`
- `prc_ai_fixtures_sha256_unique`: `UNIQUE (sha256)`
- `prc_ai_fixtures_verified_by_foreign`: `FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL`

Indexes:
- `prc_ai_fixtures_cluster_id_index`: `CREATE INDEX prc_ai_fixtures_cluster_id_index ON public.prc_ai_fixtures USING btree (cluster_id)`
- `prc_ai_fixtures_code_unique`: `CREATE UNIQUE INDEX prc_ai_fixtures_code_unique ON public.prc_ai_fixtures USING btree (code)`
- `prc_ai_fixtures_document_id_unique`: `CREATE UNIQUE INDEX prc_ai_fixtures_document_id_unique ON public.prc_ai_fixtures USING btree (document_id)`
- `prc_ai_fixtures_pkey`: `CREATE UNIQUE INDEX prc_ai_fixtures_pkey ON public.prc_ai_fixtures USING btree (id)`
- `prc_ai_fixtures_sha256_unique`: `CREATE UNIQUE INDEX prc_ai_fixtures_sha256_unique ON public.prc_ai_fixtures USING btree (sha256)`
- `prc_ai_fixtures_verified_by_index`: `CREATE INDEX prc_ai_fixtures_verified_by_index ON public.prc_ai_fixtures USING btree (verified_by)`

#### prc_ai_prompt_suggestions

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| kind | text / text | NO | text NOT NULL |
| status | text / text | NO | text DEFAULT 'pending'::text NOT NULL |
| analysis_id | bigint / int8 | YES | bigint |
| fixture_id | bigint / int8 | YES | bigint |
| eval_run_id | bigint / int8 | YES | bigint |
| llm_call_id | bigint / int8 | YES | bigint |
| prompt_key | text / text | YES | text |
| prompt_step | text / text | YES | text |
| base_version | integer / int4 | YES | integer |
| proposed_content | text / text | YES | text |
| hint | text / text | YES | text |
| document_type | text / text | NO | text NOT NULL |
| analysis_step | text / text | YES | text |
| rationale | text / text | NO | text NOT NULL |
| evidence | jsonb / jsonb | NO | jsonb DEFAULT '[]'::jsonb NOT NULL |
| decided_by | bigint / int8 | YES | bigint |
| decided_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| decision_note | text / text | YES | text |
| applied_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| applied_version | integer / int4 | YES | integer |
| reverted_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ai_prompt_suggestions_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id) ON DELETE SET NULL`
- `prc_ai_prompt_suggestions_analysis_step_check`: `CHECK (((analysis_step IS NULL) OR (analysis_step = ANY (ARRAY['screening'::text, 'extraction'::text]))))`
- `prc_ai_prompt_suggestions_decided_by_foreign`: `FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL`
- `prc_ai_prompt_suggestions_document_type_check`: `CHECK ((document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text, 'any'::text])))`
- `prc_ai_prompt_suggestions_eval_run_id_foreign`: `FOREIGN KEY (eval_run_id) REFERENCES prc_ai_eval_runs(id) ON DELETE SET NULL`
- `prc_ai_prompt_suggestions_fixture_id_foreign`: `FOREIGN KEY (fixture_id) REFERENCES prc_ai_fixtures(id) ON DELETE SET NULL`
- `prc_ai_prompt_suggestions_pkey`: `PRIMARY KEY (id)`
- `prc_ai_prompt_suggestions_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_ai_prompt_suggestions_analysis_id_index`: `CREATE INDEX prc_ai_prompt_suggestions_analysis_id_index ON public.prc_ai_prompt_suggestions USING btree (analysis_id)`
- `prc_ai_prompt_suggestions_decided_by_index`: `CREATE INDEX prc_ai_prompt_suggestions_decided_by_index ON public.prc_ai_prompt_suggestions USING btree (decided_by)`
- `prc_ai_prompt_suggestions_eval_run_id_index`: `CREATE INDEX prc_ai_prompt_suggestions_eval_run_id_index ON public.prc_ai_prompt_suggestions USING btree (eval_run_id)`
- `prc_ai_prompt_suggestions_fixture_id_index`: `CREATE INDEX prc_ai_prompt_suggestions_fixture_id_index ON public.prc_ai_prompt_suggestions USING btree (fixture_id)`
- `prc_ai_prompt_suggestions_pkey`: `CREATE UNIQUE INDEX prc_ai_prompt_suggestions_pkey ON public.prc_ai_prompt_suggestions USING btree (id)`
- `prc_ai_prompt_suggestions_prompt_key_index`: `CREATE INDEX prc_ai_prompt_suggestions_prompt_key_index ON public.prc_ai_prompt_suggestions USING btree (prompt_key)`
- `prc_ai_prompt_suggestions_status_index`: `CREATE INDEX prc_ai_prompt_suggestions_status_index ON public.prc_ai_prompt_suggestions USING btree (status)`
- `prc_ai_prompt_suggestions_uuid_unique`: `CREATE UNIQUE INDEX prc_ai_prompt_suggestions_uuid_unique ON public.prc_ai_prompt_suggestions USING btree (uuid)`

#### prc_awards

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| lot_id | bigint / int8 | YES | bigint |
| winner_org_id | bigint / int8 | YES | bigint |
| final_price | numeric / numeric | YES | numeric(18,2) |
| currency | character / bpchar | YES | character(3) |
| status | text / text | NO | text NOT NULL |
| contract_registry_number | text / text | YES | text |
| awarded_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| payload | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_awards_lot_id_foreign`: `FOREIGN KEY (lot_id) REFERENCES prc_lots(id) ON DELETE SET NULL`
- `prc_awards_pkey`: `PRIMARY KEY (id)`
- `prc_awards_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`
- `prc_awards_uuid_unique`: `UNIQUE (uuid)`
- `prc_awards_winner_org_id_foreign`: `FOREIGN KEY (winner_org_id) REFERENCES org_organizations(id) ON DELETE SET NULL`

Indexes:
- `prc_awards_lot_id_index`: `CREATE INDEX prc_awards_lot_id_index ON public.prc_awards USING btree (lot_id)`
- `prc_awards_pkey`: `CREATE UNIQUE INDEX prc_awards_pkey ON public.prc_awards USING btree (id)`
- `prc_awards_procurement_id_index`: `CREATE INDEX prc_awards_procurement_id_index ON public.prc_awards USING btree (procurement_id)`
- `prc_awards_uuid_unique`: `CREATE UNIQUE INDEX prc_awards_uuid_unique ON public.prc_awards USING btree (uuid)`
- `prc_awards_winner_org_id_index`: `CREATE INDEX prc_awards_winner_org_id_index ON public.prc_awards USING btree (winner_org_id)`

#### prc_bids

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| lot_id | bigint / int8 | YES | bigint |
| protocol_id | bigint / int8 | YES | bigint |
| organization_id | bigint / int8 | YES | bigint |
| bid_number | text / text | YES | text |
| participant_ordinal | integer / int4 | YES | integer |
| price | numeric / numeric | YES | numeric(18,2) |
| status | text / text | NO | text NOT NULL |
| reject_reason | text / text | YES | text |
| final_rank | integer / int4 | YES | integer |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_bids_lot_id_foreign`: `FOREIGN KEY (lot_id) REFERENCES prc_lots(id) ON DELETE SET NULL`
- `prc_bids_organization_id_foreign`: `FOREIGN KEY (organization_id) REFERENCES org_organizations(id) ON DELETE SET NULL`
- `prc_bids_pkey`: `PRIMARY KEY (id)`
- `prc_bids_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`
- `prc_bids_protocol_id_foreign`: `FOREIGN KEY (protocol_id) REFERENCES prc_protocols(id) ON DELETE SET NULL`

Indexes:
- `prc_bids_anonymous_participant_unique`: `CREATE UNIQUE INDEX prc_bids_anonymous_participant_unique ON public.prc_bids USING btree (procurement_id, protocol_id, participant_ordinal) NULLS NOT DISTINCT WHERE (organization_id IS NULL)`
- `prc_bids_identified_participant_unique`: `CREATE UNIQUE INDEX prc_bids_identified_participant_unique ON public.prc_bids USING btree (procurement_id, protocol_id, organization_id) NULLS NOT DISTINCT WHERE (organization_id IS NOT NULL)`
- `prc_bids_lot_id_index`: `CREATE INDEX prc_bids_lot_id_index ON public.prc_bids USING btree (lot_id)`
- `prc_bids_organization_id_index`: `CREATE INDEX prc_bids_organization_id_index ON public.prc_bids USING btree (organization_id)`
- `prc_bids_pkey`: `CREATE UNIQUE INDEX prc_bids_pkey ON public.prc_bids USING btree (id)`
- `prc_bids_procurement_id_index`: `CREATE INDEX prc_bids_procurement_id_index ON public.prc_bids USING btree (procurement_id)`
- `prc_bids_protocol_id_index`: `CREATE INDEX prc_bids_protocol_id_index ON public.prc_bids USING btree (protocol_id)`

#### prc_cancellations

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| decision_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| reason | text / text | YES | text |
| document_version_id | bigint / int8 | YES | bigint |
| reverted_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_cancellations_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id) ON DELETE SET NULL`
- `prc_cancellations_pkey`: `PRIMARY KEY (id)`
- `prc_cancellations_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`

Indexes:
- `prc_cancellations_document_version_id_index`: `CREATE INDEX prc_cancellations_document_version_id_index ON public.prc_cancellations USING btree (document_version_id)`
- `prc_cancellations_pkey`: `CREATE UNIQUE INDEX prc_cancellations_pkey ON public.prc_cancellations USING btree (id)`
- `prc_cancellations_procurement_id_index`: `CREATE INDEX prc_cancellations_procurement_id_index ON public.prc_cancellations USING btree (procurement_id)`

#### prc_clarifications

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| requested_at | date / date | YES | date |
| answered_at | date / date | YES | date |
| question | text / text | YES | text |
| answer | text / text | YES | text |
| document_version_id | bigint / int8 | YES | bigint |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_clarifications_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id) ON DELETE SET NULL`
- `prc_clarifications_pkey`: `PRIMARY KEY (id)`
- `prc_clarifications_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`

Indexes:
- `prc_clarifications_document_version_id_index`: `CREATE INDEX prc_clarifications_document_version_id_index ON public.prc_clarifications USING btree (document_version_id)`
- `prc_clarifications_pkey`: `CREATE UNIQUE INDEX prc_clarifications_pkey ON public.prc_clarifications USING btree (id)`
- `prc_clarifications_procurement_id_index`: `CREATE INDEX prc_clarifications_procurement_id_index ON public.prc_clarifications USING btree (procurement_id)`

#### prc_crawler_proxy

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| crawler_id | bigint / int8 | NO | bigint NOT NULL |
| proxy_id | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_crawler_proxy_crawler_id_foreign`: `FOREIGN KEY (crawler_id) REFERENCES prc_crawlers(id) ON DELETE CASCADE`
- `prc_crawler_proxy_crawler_id_proxy_id_unique`: `UNIQUE (crawler_id, proxy_id)`
- `prc_crawler_proxy_proxy_id_foreign`: `FOREIGN KEY (proxy_id) REFERENCES prc_proxies(id) ON DELETE CASCADE`

Indexes:
- `prc_crawler_proxy_crawler_id_proxy_id_unique`: `CREATE UNIQUE INDEX prc_crawler_proxy_crawler_id_proxy_id_unique ON public.prc_crawler_proxy USING btree (crawler_id, proxy_id)`

#### prc_crawler_runs

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| crawler_id | bigint / int8 | NO | bigint NOT NULL |
| status | text / text | NO | text NOT NULL |
| purpose | text / text | NO | text DEFAULT 'profile'::text NOT NULL |
| subject_key | text / text | YES | text |
| cursor | jsonb / jsonb | YES | jsonb |
| stats | jsonb / jsonb | YES | jsonb |
| started_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| last_heartbeat_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| attempts | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| last_error | text / text | YES | text |
| last_error_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| next_retry_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| failed_permanently | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| stop_requested_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| stop_kind | text / text | YES | text |
| stop_requested_by | bigint / int8 | YES | bigint |
| queue_job_id | text / text | YES | text |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| execution_owner_token | uuid / uuid | YES | uuid |
| execution_lease_expires_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| source_resume_claimed_until | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| source_resume_checked_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |

Constraints:
- `prc_crawler_runs_crawler_id_foreign`: `FOREIGN KEY (crawler_id) REFERENCES prc_crawlers(id)`
- `prc_crawler_runs_pkey`: `PRIMARY KEY (id)`
- `prc_crawler_runs_stop_requested_by_foreign`: `FOREIGN KEY (stop_requested_by) REFERENCES users(id) ON DELETE SET NULL`

Indexes:
- `prc_crawler_runs_crawler_id_index`: `CREATE INDEX prc_crawler_runs_crawler_id_index ON public.prc_crawler_runs USING btree (crawler_id)`
- `prc_crawler_runs_crawler_id_running_unique`: `CREATE UNIQUE INDEX prc_crawler_runs_crawler_id_running_unique ON public.prc_crawler_runs USING btree (crawler_id) WHERE (status = 'running'::text)`
- `prc_crawler_runs_execution_lease_expires_at_index`: `CREATE INDEX prc_crawler_runs_execution_lease_expires_at_index ON public.prc_crawler_runs USING btree (execution_lease_expires_at)`
- `prc_crawler_runs_pkey`: `CREATE UNIQUE INDEX prc_crawler_runs_pkey ON public.prc_crawler_runs USING btree (id)`
- `prc_runs_source_resume_due`: `CREATE INDEX prc_runs_source_resume_due ON public.prc_crawler_runs USING btree (source_resume_claimed_until, source_resume_checked_at, id) WHERE ((status = 'paused'::text) AND (purpose = 'profile'::text) AND ((stats ->> 'source_suspended'::text) = 'true'::text))`

#### prc_crawlers

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| registry_id | bigint / int8 | NO | bigint NOT NULL |
| name | text / text | NO | text NOT NULL |
| mode | text / text | NO | text NOT NULL |
| source_method | text / text | NO | text NOT NULL |
| fallback_method | text / text | YES | text |
| filter | jsonb / jsonb | YES | jsonb |
| schedule | text / text | YES | text |
| options | jsonb / jsonb | YES | jsonb |
| proxy_country | character / bpchar | YES | character(2) |
| stop_policy | text / text | NO | text DEFAULT 'default'::text NOT NULL |
| execution_lane | text / text | NO | text DEFAULT 'standard'::text NOT NULL |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| proxy_mode | text / text | NO | text DEFAULT 'default'::text NOT NULL |

Constraints:
- `prc_crawlers_pkey`: `PRIMARY KEY (id)`
- `prc_crawlers_registry_id_foreign`: `FOREIGN KEY (registry_id) REFERENCES prc_registries(id)`

Indexes:
- `prc_crawlers_pkey`: `CREATE UNIQUE INDEX prc_crawlers_pkey ON public.prc_crawlers USING btree (id)`
- `prc_crawlers_registry_id_index`: `CREATE INDEX prc_crawlers_registry_id_index ON public.prc_crawlers USING btree (registry_id)`

#### prc_document_representations

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| kind | text / text | NO | text NOT NULL |
| storage_path | text / text | NO | text NOT NULL |
| disk | text / text | YES | text |
| size_bytes | bigint / int8 | YES | bigint |
| page_count | integer / int4 | YES | integer |
| token_estimate | integer / int4 | YES | integer |
| mime | text / text | NO | text NOT NULL |
| meta | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| generated_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_document_representations_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id) ON DELETE CASCADE`
- `prc_document_representations_document_version_id_kind_unique`: `UNIQUE (document_version_id, kind)`
- `prc_document_representations_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_document_representations_document_version_id_index`: `CREATE INDEX prc_document_representations_document_version_id_index ON public.prc_document_representations USING btree (document_version_id)`
- `prc_document_representations_document_version_id_kind_unique`: `CREATE UNIQUE INDEX prc_document_representations_document_version_id_kind_unique ON public.prc_document_representations USING btree (document_version_id, kind)`
- `prc_document_representations_pkey`: `CREATE UNIQUE INDEX prc_document_representations_pkey ON public.prc_document_representations USING btree (id)`

#### prc_document_versions

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_id | bigint / int8 | NO | bigint NOT NULL |
| content_hash | text / text | NO | text NOT NULL |
| size_bytes | bigint / int8 | NO | bigint NOT NULL |
| filename | text / text | NO | text NOT NULL |
| extension | text / text | NO | text NOT NULL |
| mime | text / text | NO | text NOT NULL |
| storage_path | text / text | NO | text NOT NULL |
| disk | text / text | YES | text |
| downloaded_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| parent_version_id | bigint / int8 | YES | bigint |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| archive_entry_path | text / text | YES | text |

Constraints:
- `prc_document_versions_document_id_content_hash_unique`: `UNIQUE (document_id, content_hash)`
- `prc_document_versions_document_id_foreign`: `FOREIGN KEY (document_id) REFERENCES prc_documents(id)`
- `prc_document_versions_parent_version_id_foreign`: `FOREIGN KEY (parent_version_id) REFERENCES prc_document_versions(id) ON DELETE SET NULL`
- `prc_document_versions_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_document_versions_content_hash_index`: `CREATE INDEX prc_document_versions_content_hash_index ON public.prc_document_versions USING btree (content_hash)`
- `prc_document_versions_document_id_content_hash_unique`: `CREATE UNIQUE INDEX prc_document_versions_document_id_content_hash_unique ON public.prc_document_versions USING btree (document_id, content_hash)`
- `prc_document_versions_document_id_index`: `CREATE INDEX prc_document_versions_document_id_index ON public.prc_document_versions USING btree (document_id)`
- `prc_document_versions_parent_archive_entry_path_unique`: `CREATE UNIQUE INDEX prc_document_versions_parent_archive_entry_path_unique ON public.prc_document_versions USING btree (parent_version_id, archive_entry_path) WHERE ((parent_version_id IS NOT NULL) AND (archive_entry_path IS NOT NULL))`
- `prc_document_versions_parent_version_id_index`: `CREATE INDEX prc_document_versions_parent_version_id_index ON public.prc_document_versions USING btree (parent_version_id)`
- `prc_document_versions_pkey`: `CREATE UNIQUE INDEX prc_document_versions_pkey ON public.prc_document_versions USING btree (id)`

#### prc_documents

Owner: Repositories/Procurement (subject authority; Process head fields from P1.6); retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| procurement_id | bigint / int8 | YES | bigint |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| external_id | text / text | YES | text |
| title | text / text | NO | text NOT NULL |
| kind | text / text | YES | text |
| source_url | text / text | YES | text |
| status | text / text | NO | text NOT NULL |
| first_seen_revision_id | bigint / int8 | YES | bigint |
| removed_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| attempts | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| last_error | text / text | YES | text |
| last_error_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| next_retry_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| failed_permanently | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| locked_by | text / text | YES | text |
| locked_until | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| unprocessable_reason | character varying / varchar | YES | character varying(64) |

Constraints:
- `prc_documents_pkey`: `PRIMARY KEY (id)`
- `prc_documents_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_documents_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`
- `prc_documents_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_documents_pkey`: `CREATE UNIQUE INDEX prc_documents_pkey ON public.prc_documents USING btree (id)`
- `prc_documents_platform_id_index`: `CREATE INDEX prc_documents_platform_id_index ON public.prc_documents USING btree (platform_id)`
- `prc_documents_procurement_external_id_unique`: `CREATE UNIQUE INDEX prc_documents_procurement_external_id_unique ON public.prc_documents USING btree (procurement_id, external_id) WHERE (external_id IS NOT NULL)`
- `prc_documents_procurement_id_index`: `CREATE INDEX prc_documents_procurement_id_index ON public.prc_documents USING btree (procurement_id)`
- `prc_documents_unprocessable_reason_index`: `CREATE INDEX prc_documents_unprocessable_reason_index ON public.prc_documents USING btree (unprocessable_reason)`
- `prc_documents_uuid_unique`: `CREATE UNIQUE INDEX prc_documents_uuid_unique ON public.prc_documents USING btree (uuid)`

#### prc_eis_details

Owner: Platforms/Eis (extension writer P2.14); retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| ikz_year | integer / int4 | YES | integer |
| ikz_customer_code | text / text | YES | text |
| ikz_plan_number | text / text | YES | text |
| ikz_plangraph_number | text / text | YES | text |
| ikz_okpd_class | text / text | YES | text |
| ikz_okpd_subclass | text / text | YES | text |
| ikz_okpd_group | text / text | YES | text |
| ikz_kvr | text / text | YES | text |
| plan_graph_position | text / text | YES | text |
| plan_graph_url | text / text | YES | text |
| bid_security_required | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| bid_security_percent | numeric / numeric | YES | numeric(5,2) |
| bid_security_amount | numeric / numeric | YES | numeric(18,2) |
| performance_security_required | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| performance_security_percent | numeric / numeric | YES | numeric(5,2) |
| performance_security_amount | numeric / numeric | YES | numeric(18,2) |
| is_smp_only | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| anti_dumping_applied | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| treasury_support | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| advantages | jsonb / jsonb | YES | jsonb |
| national_regime | jsonb / jsonb | YES | jsonb |
| participant_requirements | jsonb / jsonb | YES | jsonb |
| etp_name | text / text | YES | text |
| etp_url | text / text | YES | text |
| execution_starts_at | text / text | YES | text |
| execution_ends_at | text / text | YES | text |
| contact_person | jsonb / jsonb | YES | jsonb |
| print_form_urls | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_eis_details_pkey`: `PRIMARY KEY (procurement_id)`
- `prc_eis_details_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`

Indexes:
- `prc_eis_details_pkey`: `CREATE UNIQUE INDEX prc_eis_details_pkey ON public.prc_eis_details USING btree (procurement_id)`

#### prc_estimate_items

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| estimate_id | bigint / int8 | NO | bigint NOT NULL |
| section_id | bigint / int8 | YES | bigint |
| row_index | integer / int4 | NO | integer NOT NULL |
| code | text / text | YES | text |
| name | text / text | NO | text NOT NULL |
| unit | text / text | YES | text |
| unit_base | text / text | YES | text |
| unit_multiplier | numeric / numeric | YES | numeric(18,6) |
| quantity | numeric / numeric | YES | numeric(18,6) |
| unit_price_base | numeric / numeric | YES | numeric(18,2) |
| unit_price_curr | numeric / numeric | YES | numeric(18,2) |
| total_price_base | numeric / numeric | YES | numeric(18,2) |
| total_price_curr | numeric / numeric | YES | numeric(18,2) |
| index_code | text / text | YES | text |
| index_value | numeric / numeric | YES | numeric(18,6) |
| source_position_number | text / text | YES | text |
| work_type_id | bigint / int8 | YES | bigint |
| attributes | jsonb / jsonb | YES | jsonb |
| quality | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimate_items_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_items_estimate_id_row_index_unique`: `UNIQUE (estimate_id, row_index)`
- `prc_estimate_items_pkey`: `PRIMARY KEY (id)`
- `prc_estimate_items_section_id_foreign`: `FOREIGN KEY (section_id) REFERENCES prc_estimate_sections(id) ON DELETE SET NULL`
- `prc_estimate_items_work_type_id_foreign`: `FOREIGN KEY (work_type_id) REFERENCES prc_estimate_work_types(id) ON DELETE SET NULL`

Indexes:
- `prc_estimate_items_estimate_id_index`: `CREATE INDEX prc_estimate_items_estimate_id_index ON public.prc_estimate_items USING btree (estimate_id)`
- `prc_estimate_items_estimate_id_row_index_unique`: `CREATE UNIQUE INDEX prc_estimate_items_estimate_id_row_index_unique ON public.prc_estimate_items USING btree (estimate_id, row_index)`
- `prc_estimate_items_pkey`: `CREATE UNIQUE INDEX prc_estimate_items_pkey ON public.prc_estimate_items USING btree (id)`
- `prc_estimate_items_quality_index`: `CREATE INDEX prc_estimate_items_quality_index ON public.prc_estimate_items USING btree (estimate_id) WHERE (quality IS NOT NULL)`
- `prc_estimate_items_section_id_index`: `CREATE INDEX prc_estimate_items_section_id_index ON public.prc_estimate_items USING btree (section_id)`
- `prc_estimate_items_work_type_id_index`: `CREATE INDEX prc_estimate_items_work_type_id_index ON public.prc_estimate_items USING btree (work_type_id)`

#### prc_estimate_resources

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| item_id | bigint / int8 | NO | bigint NOT NULL |
| resource_type | text / text | NO | text NOT NULL |
| code | text / text | YES | text |
| name | text / text | NO | text NOT NULL |
| unit | text / text | YES | text |
| unit_base | text / text | YES | text |
| unit_multiplier | numeric / numeric | YES | numeric(18,6) |
| quantity | numeric / numeric | YES | numeric(18,6) |
| price_base | numeric / numeric | YES | numeric(18,2) |
| price_curr | numeric / numeric | YES | numeric(18,2) |
| index_code | text / text | YES | text |
| index_value | numeric / numeric | YES | numeric(18,6) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimate_resources_item_id_foreign`: `FOREIGN KEY (item_id) REFERENCES prc_estimate_items(id) ON DELETE CASCADE`
- `prc_estimate_resources_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_estimate_resources_item_id_index`: `CREATE INDEX prc_estimate_resources_item_id_index ON public.prc_estimate_resources USING btree (item_id)`
- `prc_estimate_resources_pkey`: `CREATE UNIQUE INDEX prc_estimate_resources_pkey ON public.prc_estimate_resources USING btree (id)`

#### prc_estimate_sections

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| estimate_id | bigint / int8 | NO | bigint NOT NULL |
| parent_id | bigint / int8 | YES | bigint |
| position | integer / int4 | NO | integer NOT NULL |
| title | text / text | NO | text NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimate_sections_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_sections_parent_id_foreign`: `FOREIGN KEY (parent_id) REFERENCES prc_estimate_sections(id) ON DELETE SET NULL`
- `prc_estimate_sections_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_estimate_sections_estimate_id_index`: `CREATE INDEX prc_estimate_sections_estimate_id_index ON public.prc_estimate_sections USING btree (estimate_id)`
- `prc_estimate_sections_parent_id_index`: `CREATE INDEX prc_estimate_sections_parent_id_index ON public.prc_estimate_sections USING btree (parent_id)`
- `prc_estimate_sections_pkey`: `CREATE UNIQUE INDEX prc_estimate_sections_pkey ON public.prc_estimate_sections USING btree (id)`

#### prc_estimate_totals

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| estimate_id | bigint / int8 | NO | bigint NOT NULL |
| parent_id | bigint / int8 | YES | bigint |
| position | integer / int4 | NO | integer NOT NULL |
| label | text / text | NO | text NOT NULL |
| kind | text / text | YES | text |
| amount | numeric / numeric | YES | numeric(18,2) |
| chapter_number | smallint / int2 | YES | smallint |
| rate | numeric / numeric | YES | numeric(18,6) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimate_totals_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_totals_parent_id_foreign`: `FOREIGN KEY (parent_id) REFERENCES prc_estimate_totals(id) ON DELETE SET NULL`
- `prc_estimate_totals_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_estimate_totals_estimate_id_index`: `CREATE INDEX prc_estimate_totals_estimate_id_index ON public.prc_estimate_totals USING btree (estimate_id)`
- `prc_estimate_totals_parent_id_index`: `CREATE INDEX prc_estimate_totals_parent_id_index ON public.prc_estimate_totals USING btree (parent_id)`
- `prc_estimate_totals_pkey`: `CREATE UNIQUE INDEX prc_estimate_totals_pkey ON public.prc_estimate_totals USING btree (id)`

#### prc_estimate_work_types

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| estimate_id | bigint / int8 | NO | bigint NOT NULL |
| parent_id | bigint / int8 | YES | bigint |
| position | integer / int4 | NO | integer NOT NULL |
| title | text / text | NO | text NOT NULL |
| overhead_code | text / text | YES | text |
| overhead_rate | numeric / numeric | YES | numeric(18,6) |
| overhead_rate_curr | numeric / numeric | YES | numeric(18,6) |
| profit_code | text / text | YES | text |
| profit_rate | numeric / numeric | YES | numeric(18,6) |
| profit_rate_curr | numeric / numeric | YES | numeric(18,6) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimate_work_types_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_work_types_parent_id_foreign`: `FOREIGN KEY (parent_id) REFERENCES prc_estimate_work_types(id) ON DELETE CASCADE`
- `prc_estimate_work_types_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_estimate_work_types_estimate_id_index`: `CREATE INDEX prc_estimate_work_types_estimate_id_index ON public.prc_estimate_work_types USING btree (estimate_id)`
- `prc_estimate_work_types_parent_id_index`: `CREATE INDEX prc_estimate_work_types_parent_id_index ON public.prc_estimate_work_types USING btree (parent_id)`
- `prc_estimate_work_types_pkey`: `CREATE UNIQUE INDEX prc_estimate_work_types_pkey ON public.prc_estimate_work_types USING btree (id)`

#### prc_estimates

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| analysis_id | bigint / int8 | NO | bigint NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | YES | bigint |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| ordinal | smallint / int2 | NO | smallint DEFAULT '1'::smallint NOT NULL |
| estimate_number | text / text | YES | text |
| total_amount | numeric / numeric | YES | numeric(18,2) |
| currency | character / bpchar | YES | character(3) |
| base_year | integer / int4 | YES | integer |
| price_level | text / text | YES | text |
| regulatory_base | text / text | YES | text |
| calc_method | text / text | YES | text |
| labor_hours_workers | numeric / numeric | YES | numeric(18,6) |
| labor_hours_machinists | numeric / numeric | YES | numeric(18,6) |
| document_number | text / text | YES | text |
| document_date | date / date | YES | date |
| period_start | date / date | YES | date |
| period_end | date / date | YES | date |
| passport | jsonb / jsonb | YES | jsonb |
| raw_result | jsonb / jsonb | YES | jsonb |
| raw_result_ref | text / text | YES | text |
| raw_result_disk | text / text | YES | text |
| rows_expected | integer / int4 | YES | integer |
| rows_extracted | integer / int4 | YES | integer |
| validation_status | text / text | NO | text NOT NULL |
| validation_notes | jsonb / jsonb | YES | jsonb |
| verified_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| verified_by | bigint / int8 | YES | bigint |
| source_sheet_name | text / text | YES | text |
| source_row_start | smallint / int2 | YES | smallint |
| source_row_end | smallint / int2 | YES | smallint |
| source_page_start | smallint / int2 | YES | smallint |
| source_page_end | smallint / int2 | YES | smallint |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_estimates_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id)`
- `prc_estimates_analysis_id_ordinal_unique`: `UNIQUE (analysis_id, ordinal)`
- `prc_estimates_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_estimates_pkey`: `PRIMARY KEY (id)`
- `prc_estimates_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_estimates_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`
- `prc_estimates_uuid_unique`: `UNIQUE (uuid)`
- `prc_estimates_verified_by_foreign`: `FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL`

Indexes:
- `prc_estimates_analysis_id_index`: `CREATE INDEX prc_estimates_analysis_id_index ON public.prc_estimates USING btree (analysis_id)`
- `prc_estimates_analysis_id_ordinal_unique`: `CREATE UNIQUE INDEX prc_estimates_analysis_id_ordinal_unique ON public.prc_estimates USING btree (analysis_id, ordinal)`
- `prc_estimates_document_version_id_index`: `CREATE INDEX prc_estimates_document_version_id_index ON public.prc_estimates USING btree (document_version_id)`
- `prc_estimates_pkey`: `CREATE UNIQUE INDEX prc_estimates_pkey ON public.prc_estimates USING btree (id)`
- `prc_estimates_platform_id_index`: `CREATE INDEX prc_estimates_platform_id_index ON public.prc_estimates USING btree (platform_id)`
- `prc_estimates_procurement_id_index`: `CREATE INDEX prc_estimates_procurement_id_index ON public.prc_estimates USING btree (procurement_id)`
- `prc_estimates_uuid_unique`: `CREATE UNIQUE INDEX prc_estimates_uuid_unique ON public.prc_estimates USING btree (uuid)`
- `prc_estimates_verified_by_index`: `CREATE INDEX prc_estimates_verified_by_index ON public.prc_estimates USING btree (verified_by)`

#### prc_events

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| revision_id | bigint / int8 | YES | bigint |
| event_type | text / text | NO | text NOT NULL |
| entity_ref | jsonb / jsonb | YES | jsonb |
| field | text / text | YES | text |
| old_value | text / text | YES | text |
| new_value | text / text | YES | text |
| payload | jsonb / jsonb | YES | jsonb |
| occurred_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| created_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_events_pkey`: `PRIMARY KEY (id, occurred_at)`
- `prc_events_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`

Indexes:
- `prc_events_pkey`: `CREATE UNIQUE INDEX prc_events_pkey ON ONLY public.prc_events USING btree (id, occurred_at)`
- `prc_events_procurement_occurred_index`: `CREATE INDEX prc_events_procurement_occurred_index ON ONLY public.prc_events USING btree (procurement_id, occurred_at)`
- `prc_events_type_occurred_index`: `CREATE INDEX prc_events_type_occurred_index ON ONLY public.prc_events USING btree (event_type, occurred_at)`

#### prc_fetches

Owner: Procurement; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| crawler_run_id | bigint / int8 | YES | bigint |
| stage | text / text | YES | text |
| procurement_id | bigint / int8 | YES | bigint |
| method | text / text | NO | text NOT NULL |
| request_params | jsonb / jsonb | YES | jsonb |
| artifact_ref | text / text | YES | text |
| http_status | integer / int4 | YES | integer |
| duration_ms | integer / int4 | YES | integer |
| wait_ms | integer / int4 | YES | integer |
| rows_total | integer / int4 | YES | integer |
| rows_new | integer / int4 | YES | integer |
| rows_updated | integer / int4 | YES | integer |
| rows_parsed | integer / int4 | YES | integer |
| rows_errors | integer / int4 | YES | integer |
| dedup_signature | text / text | YES | text |
| proxy_id | bigint / int8 | YES | bigint |
| fetched_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| created_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| proxy_source | text / text | YES | text |

Constraints:
- `prc_fetches_crawler_run_id_foreign`: `FOREIGN KEY (crawler_run_id) REFERENCES prc_crawler_runs(id)`
- `prc_fetches_pkey`: `PRIMARY KEY (id, fetched_at)`
- `prc_fetches_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id)`
- `prc_fetches_proxy_id_foreign`: `FOREIGN KEY (proxy_id) REFERENCES prc_proxies(id)`

Indexes:
- `prc_fetches_crawler_run_id_index`: `CREATE INDEX prc_fetches_crawler_run_id_index ON ONLY public.prc_fetches USING btree (crawler_run_id)`
- `prc_fetches_pkey`: `CREATE UNIQUE INDEX prc_fetches_pkey ON ONLY public.prc_fetches USING btree (id, fetched_at)`
- `prc_fetches_procurement_id_index`: `CREATE INDEX prc_fetches_procurement_id_index ON ONLY public.prc_fetches USING btree (procurement_id)`
- `prc_fetches_proxy_id_index`: `CREATE INDEX prc_fetches_proxy_id_index ON ONLY public.prc_fetches USING btree (proxy_id)`

#### prc_financing

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| lot_id | bigint / int8 | YES | bigint |
| kind | text / text | NO | text NOT NULL |
| kbk | text / text | YES | text |
| kbk_ref_id | bigint / int8 | YES | bigint |
| kvr | text / text | YES | text |
| receipt_code | text / text | YES | text |
| fiscal_year | integer / int4 | YES | integer |
| is_beyond_horizon | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| amount | numeric / numeric | NO | numeric(18,2) NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_financing_kbk_ref_id_foreign`: `FOREIGN KEY (kbk_ref_id) REFERENCES ref_kbk(id) ON DELETE SET NULL`
- `prc_financing_lot_id_foreign`: `FOREIGN KEY (lot_id) REFERENCES prc_lots(id) ON DELETE SET NULL`
- `prc_financing_pkey`: `PRIMARY KEY (id)`
- `prc_financing_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`

Indexes:
- `prc_financing_kbk_ref_id_index`: `CREATE INDEX prc_financing_kbk_ref_id_index ON public.prc_financing USING btree (kbk_ref_id)`
- `prc_financing_lot_id_index`: `CREATE INDEX prc_financing_lot_id_index ON public.prc_financing USING btree (lot_id)`
- `prc_financing_pkey`: `CREATE UNIQUE INDEX prc_financing_pkey ON public.prc_financing USING btree (id)`
- `prc_financing_procurement_id_index`: `CREATE INDEX prc_financing_procurement_id_index ON public.prc_financing USING btree (procurement_id)`

#### prc_ignored_items

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| registry_id | bigint / int8 | NO | bigint NOT NULL |
| registry_number | text / text | NO | text NOT NULL |
| reason_category | text / text | NO | text NOT NULL |
| reason | text / text | YES | text |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_ignored_items_pkey`: `PRIMARY KEY (id)`
- `prc_ignored_items_registry_id_foreign`: `FOREIGN KEY (registry_id) REFERENCES prc_registries(id)`
- `prc_ignored_items_registry_id_registry_number_unique`: `UNIQUE (registry_id, registry_number)`

Indexes:
- `prc_ignored_items_pkey`: `CREATE UNIQUE INDEX prc_ignored_items_pkey ON public.prc_ignored_items USING btree (id)`
- `prc_ignored_items_reason_category_index`: `CREATE INDEX prc_ignored_items_reason_category_index ON public.prc_ignored_items USING btree (reason_category)`
- `prc_ignored_items_registry_id_index`: `CREATE INDEX prc_ignored_items_registry_id_index ON public.prc_ignored_items USING btree (registry_id)`
- `prc_ignored_items_registry_id_registry_number_unique`: `CREATE UNIQUE INDEX prc_ignored_items_registry_id_registry_number_unique ON public.prc_ignored_items USING btree (registry_id, registry_number)`

#### prc_items

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| lot_id | bigint / int8 | YES | bigint |
| position_number | integer / int4 | YES | integer |
| name | text / text | NO | text NOT NULL |
| characteristics | text / text | YES | text |
| classifier_scheme | text / text | NO | text NOT NULL |
| classifier_code | text / text | NO | text NOT NULL |
| okpd2_ref_id | bigint / int8 | YES | bigint |
| ktru_ref_id | bigint / int8 | YES | bigint |
| quantity | numeric / numeric | YES | numeric(18,6) |
| unit | text / text | YES | text |
| unit_price | numeric / numeric | YES | numeric(18,2) |
| total_cost | numeric / numeric | YES | numeric(18,2) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_items_ktru_ref_id_foreign`: `FOREIGN KEY (ktru_ref_id) REFERENCES ref_ktru(id) ON DELETE SET NULL`
- `prc_items_lot_id_foreign`: `FOREIGN KEY (lot_id) REFERENCES prc_lots(id) ON DELETE SET NULL`
- `prc_items_okpd2_ref_id_foreign`: `FOREIGN KEY (okpd2_ref_id) REFERENCES ref_okpd2(id) ON DELETE SET NULL`
- `prc_items_pkey`: `PRIMARY KEY (id)`
- `prc_items_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`

Indexes:
- `prc_items_classifier_code_index`: `CREATE INDEX prc_items_classifier_code_index ON public.prc_items USING btree (classifier_code)`
- `prc_items_ktru_ref_id_index`: `CREATE INDEX prc_items_ktru_ref_id_index ON public.prc_items USING btree (ktru_ref_id)`
- `prc_items_lot_id_index`: `CREATE INDEX prc_items_lot_id_index ON public.prc_items USING btree (lot_id)`
- `prc_items_okpd2_ref_id_index`: `CREATE INDEX prc_items_okpd2_ref_id_index ON public.prc_items USING btree (okpd2_ref_id)`
- `prc_items_pkey`: `CREATE UNIQUE INDEX prc_items_pkey ON public.prc_items USING btree (id)`
- `prc_items_procurement_id_index`: `CREATE INDEX prc_items_procurement_id_index ON public.prc_items USING btree (procurement_id)`

#### prc_lots

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| lot_number | integer / int4 | NO | integer NOT NULL |
| lot_guid | text / text | YES | text |
| subject | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| is_centralized | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| initial_price | numeric / numeric | YES | numeric(18,2) |
| currency | character / bpchar | YES | character(3) |
| okpd2_code | text / text | YES | text |
| okved_code | text / text | YES | text |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_lots_pkey`: `PRIMARY KEY (id)`
- `prc_lots_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`
- `prc_lots_procurement_id_lot_number_unique`: `UNIQUE (procurement_id, lot_number)`

Indexes:
- `prc_lots_pkey`: `CREATE UNIQUE INDEX prc_lots_pkey ON public.prc_lots USING btree (id)`
- `prc_lots_procurement_id_lot_number_unique`: `CREATE UNIQUE INDEX prc_lots_procurement_id_lot_number_unique ON public.prc_lots USING btree (procurement_id, lot_number)`
- `prc_lots_procurement_lot_guid_unique`: `CREATE UNIQUE INDEX prc_lots_procurement_lot_guid_unique ON public.prc_lots USING btree (procurement_id, lot_guid) WHERE (lot_guid IS NOT NULL)`

#### prc_okpd2_nodes

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| external_id | character varying / varchar | NO | character varying(255) NOT NULL |
| parent_code | character varying / varchar | YES | character varying(255) |
| level | smallint / int2 | NO | smallint NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| synced_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_okpd2_nodes_pkey`: `PRIMARY KEY (id)`
- `prc_okpd2_nodes_platform_id_code_unique`: `UNIQUE (platform_id, code)`
- `prc_okpd2_nodes_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`

Indexes:
- `prc_okpd2_nodes_parent_code_index`: `CREATE INDEX prc_okpd2_nodes_parent_code_index ON public.prc_okpd2_nodes USING btree (parent_code)`
- `prc_okpd2_nodes_pkey`: `CREATE UNIQUE INDEX prc_okpd2_nodes_pkey ON public.prc_okpd2_nodes USING btree (id)`
- `prc_okpd2_nodes_platform_id_code_unique`: `CREATE UNIQUE INDEX prc_okpd2_nodes_platform_id_code_unique ON public.prc_okpd2_nodes USING btree (platform_id, code)`
- `prc_okpd2_nodes_platform_id_parent_code_index`: `CREATE INDEX prc_okpd2_nodes_platform_id_parent_code_index ON public.prc_okpd2_nodes USING btree (platform_id, parent_code)`

#### prc_operation_logs

Owner: Procurement; retention: technical.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| level | text / text | NO | text NOT NULL |
| stage | text / text | YES | text |
| source | text / text | YES | text |
| entity_type | text / text | YES | text |
| entity_id | bigint / int8 | YES | bigint |
| message | text / text | NO | text NOT NULL |
| context | jsonb / jsonb | YES | jsonb |
| trace | text / text | YES | text |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_operation_logs_pkey`: `PRIMARY KEY (id, created_at)`

Indexes:
- `prc_operation_logs_entity_index`: `CREATE INDEX prc_operation_logs_entity_index ON ONLY public.prc_operation_logs USING btree (entity_type, entity_id)`
- `prc_operation_logs_pkey`: `CREATE UNIQUE INDEX prc_operation_logs_pkey ON ONLY public.prc_operation_logs USING btree (id, created_at)`

#### prc_platform_checks

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| check_kind | text / text | NO | text NOT NULL |
| verdict | text / text | NO | text NOT NULL |
| measured | jsonb / jsonb | NO | jsonb NOT NULL |
| baseline_sha256 | text / text | YES | text |
| window_from | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| window_to | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| sample_size | integer / int4 | NO | integer NOT NULL |
| requests_used | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| message | text / text | NO | text NOT NULL |
| checked_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_platform_checks_pkey`: `PRIMARY KEY (id)`
- `prc_platform_checks_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`

Indexes:
- `prc_platform_checks_pkey`: `CREATE UNIQUE INDEX prc_platform_checks_pkey ON public.prc_platform_checks USING btree (id)`
- `prc_platform_checks_platform_id_check_kind_checked_at_index`: `CREATE INDEX prc_platform_checks_platform_id_check_kind_checked_at_index ON public.prc_platform_checks USING btree (platform_id, check_kind, checked_at)`

#### prc_platforms

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| base_url | character varying / varchar | YES | character varying(255) |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| is_public | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| throttle | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_platforms_code_unique`: `UNIQUE (code)`
- `prc_platforms_pkey`: `PRIMARY KEY (id)`
- `prc_platforms_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_platforms_code_unique`: `CREATE UNIQUE INDEX prc_platforms_code_unique ON public.prc_platforms USING btree (code)`
- `prc_platforms_pkey`: `CREATE UNIQUE INDEX prc_platforms_pkey ON public.prc_platforms USING btree (id)`
- `prc_platforms_uuid_unique`: `CREATE UNIQUE INDEX prc_platforms_uuid_unique ON public.prc_platforms USING btree (uuid)`

#### prc_procurement_parties

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| organization_id | bigint / int8 | NO | bigint NOT NULL |
| role | character varying / varchar | NO | character varying(255) NOT NULL |
| first_seen_revision_id | bigint / int8 | YES | bigint |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_procurement_parties_organization_id_foreign`: `FOREIGN KEY (organization_id) REFERENCES org_organizations(id) ON DELETE CASCADE`
- `prc_procurement_parties_pkey`: `PRIMARY KEY (id)`
- `prc_procurement_parties_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`
- `prc_procurement_parties_procurement_id_organization_id_role_uni`: `UNIQUE (procurement_id, organization_id, role)`

Indexes:
- `prc_procurement_parties_organization_id_index`: `CREATE INDEX prc_procurement_parties_organization_id_index ON public.prc_procurement_parties USING btree (organization_id)`
- `prc_procurement_parties_pkey`: `CREATE UNIQUE INDEX prc_procurement_parties_pkey ON public.prc_procurement_parties USING btree (id)`
- `prc_procurement_parties_procurement_id_index`: `CREATE INDEX prc_procurement_parties_procurement_id_index ON public.prc_procurement_parties USING btree (procurement_id)`
- `prc_procurement_parties_procurement_id_organization_id_role_uni`: `CREATE UNIQUE INDEX prc_procurement_parties_procurement_id_organization_id_role_uni ON public.prc_procurement_parties USING btree (procurement_id, organization_id, role)`

#### prc_procurements

Owner: Repositories/Procurement (subject authority; Process head fields from P1.6); retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| registry_id | bigint / int8 | NO | bigint NOT NULL |
| registry_number | text / text | NO | text NOT NULL |
| etp_number | text / text | YES | text |
| ikz | text / text | YES | text |
| title | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| source_stage | text / text | NO | text NOT NULL |
| procedure_type | text / text | YES | text |
| procedure_type_raw | text / text | YES | text |
| customer_org_id | bigint / int8 | YES | bigint |
| initial_price | numeric / numeric | YES | numeric(18,2) |
| currency | character / bpchar | YES | character(3) |
| advance_percent | numeric / numeric | YES | numeric(5,2) |
| okved_code | text / text | YES | text |
| okved_additional | text / text | YES | text |
| region_code | text / text | YES | text |
| published_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| source_updated_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| bidding_starts_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| bidding_ends_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| summary_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| detail_url | text / text | YES | text |
| detail_fetched_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| detail_attempts | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| detail_last_error | text / text | YES | text |
| detail_next_retry_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| detail_failed | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| detail_parse_outcome | jsonb / jsonb | YES | jsonb |
| succeeded_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| registry_guid | text / text | YES | text |
| current_revision_id | bigint / int8 | YES | bigint |
| current_revision_detected_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| revision_count | integer / int4 | NO | integer DEFAULT 1 NOT NULL |
| ignored_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| ignore_reason | text / text | YES | text |
| attributes | jsonb / jsonb | YES | jsonb |
| first_seen_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| last_seen_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| source_updated_has_day_precision | boolean / bool | YES | boolean |
| source_updated_before | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| source_observed_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| source_order_ambiguous | boolean / bool | NO | boolean DEFAULT false NOT NULL |

Constraints:
- `prc_procurements_customer_org_id_foreign`: `FOREIGN KEY (customer_org_id) REFERENCES org_organizations(id) ON DELETE SET NULL`
- `prc_procurements_pkey`: `PRIMARY KEY (id)`
- `prc_procurements_registry_id_foreign`: `FOREIGN KEY (registry_id) REFERENCES prc_registries(id)`
- `prc_procurements_registry_id_registry_number_unique`: `UNIQUE (registry_id, registry_number)`
- `prc_procurements_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_procurements_customer_org_id_index`: `CREATE INDEX prc_procurements_customer_org_id_index ON public.prc_procurements USING btree (customer_org_id)`
- `prc_procurements_ikz_index`: `CREATE INDEX prc_procurements_ikz_index ON public.prc_procurements USING btree (ikz)`
- `prc_procurements_okved_additional_index`: `CREATE INDEX prc_procurements_okved_additional_index ON public.prc_procurements USING btree (okved_additional)`
- `prc_procurements_okved_code_index`: `CREATE INDEX prc_procurements_okved_code_index ON public.prc_procurements USING btree (okved_code)`
- `prc_procurements_pkey`: `CREATE UNIQUE INDEX prc_procurements_pkey ON public.prc_procurements USING btree (id)`
- `prc_procurements_published_at_index`: `CREATE INDEX prc_procurements_published_at_index ON public.prc_procurements USING btree (published_at)`
- `prc_procurements_region_code_index`: `CREATE INDEX prc_procurements_region_code_index ON public.prc_procurements USING btree (region_code)`
- `prc_procurements_registry_guid_index`: `CREATE INDEX prc_procurements_registry_guid_index ON public.prc_procurements USING btree (registry_guid)`
- `prc_procurements_registry_id_registry_number_unique`: `CREATE UNIQUE INDEX prc_procurements_registry_id_registry_number_unique ON public.prc_procurements USING btree (registry_id, registry_number)`
- `prc_procurements_registry_id_status_published_at_index`: `CREATE INDEX prc_procurements_registry_id_status_published_at_index ON public.prc_procurements USING btree (registry_id, status, published_at)`
- `prc_procurements_source_updated_at_index`: `CREATE INDEX prc_procurements_source_updated_at_index ON public.prc_procurements USING btree (source_updated_at)`
- `prc_procurements_status_bidding_ends_at_index`: `CREATE INDEX prc_procurements_status_bidding_ends_at_index ON public.prc_procurements USING btree (status, bidding_ends_at)`
- `prc_procurements_succeeded_at_index`: `CREATE INDEX prc_procurements_succeeded_at_index ON public.prc_procurements USING btree (succeeded_at) WHERE (succeeded_at IS NOT NULL)`
- `prc_procurements_uuid_unique`: `CREATE UNIQUE INDEX prc_procurements_uuid_unique ON public.prc_procurements USING btree (uuid)`

#### prc_protocols

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| type | text / text | NO | text NOT NULL |
| stage | text / text | YES | text |
| source_number | text / text | YES | text |
| signed_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| published_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| document_version_id | bigint / int8 | YES | bigint |
| payload | jsonb / jsonb | YES | jsonb |
| created_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |

Constraints:
- `prc_protocols_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id) ON DELETE SET NULL`
- `prc_protocols_pkey`: `PRIMARY KEY (id)`
- `prc_protocols_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE CASCADE`
- `prc_protocols_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_protocols_document_version_id_index`: `CREATE INDEX prc_protocols_document_version_id_index ON public.prc_protocols USING btree (document_version_id)`
- `prc_protocols_pkey`: `CREATE UNIQUE INDEX prc_protocols_pkey ON public.prc_protocols USING btree (id)`
- `prc_protocols_procurement_id_index`: `CREATE INDEX prc_protocols_procurement_id_index ON public.prc_protocols USING btree (procurement_id)`
- `prc_protocols_uuid_unique`: `CREATE UNIQUE INDEX prc_protocols_uuid_unique ON public.prc_protocols USING btree (uuid)`

#### prc_proxies

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| service | text / text | NO | text NOT NULL |
| host | text / text | NO | text NOT NULL |
| port | integer / int4 | YES | integer |
| username | text / text | YES | text |
| password | text / text | YES | text |
| country | character / bpchar | YES | character(2) |
| scopes | jsonb / jsonb | NO | jsonb DEFAULT '[]'::jsonb NOT NULL |
| open_until | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| open_count | smallint / int2 | NO | smallint DEFAULT '0'::smallint NOT NULL |
| last_failure_reason | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| fails_count | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| disabled_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| disabled_reason | text / text | YES | text |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_proxies_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_proxies_is_active_country_index`: `CREATE INDEX prc_proxies_is_active_country_index ON public.prc_proxies USING btree (is_active, country)`
- `prc_proxies_pkey`: `CREATE UNIQUE INDEX prc_proxies_pkey ON public.prc_proxies USING btree (id)`
- `prc_proxies_scopes_gin`: `CREATE INDEX prc_proxies_scopes_gin ON public.prc_proxies USING gin (scopes)`

#### prc_proxy_checks

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| proxy_id | bigint / int8 | NO | bigint NOT NULL |
| checked_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| success | boolean / bool | NO | boolean NOT NULL |
| latency_ms | integer / int4 | YES | integer |
| error | text / text | YES | text |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_proxy_checks_pkey`: `PRIMARY KEY (id)`
- `prc_proxy_checks_proxy_id_foreign`: `FOREIGN KEY (proxy_id) REFERENCES prc_proxies(id)`

Indexes:
- `prc_proxy_checks_pkey`: `CREATE UNIQUE INDEX prc_proxy_checks_pkey ON public.prc_proxy_checks USING btree (id)`
- `prc_proxy_checks_proxy_id_index`: `CREATE INDEX prc_proxy_checks_proxy_id_index ON public.prc_proxy_checks USING btree (proxy_id)`

#### prc_proxy_targets

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| proxy_id | bigint / int8 | NO | bigint NOT NULL |
| target | text / text | NO | text NOT NULL |
| success_ratio | double precision / float8 | NO | double precision DEFAULT '1'::double precision NOT NULL |
| latency_ms_avg | integer / int4 | YES | integer |
| consecutive_failures | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| open_until | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| open_count | smallint / int2 | NO | smallint DEFAULT '0'::smallint NOT NULL |
| last_failure_reason | text / text | YES | text |
| last_outcome_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_proxy_targets_pkey`: `PRIMARY KEY (id)`
- `prc_proxy_targets_proxy_id_foreign`: `FOREIGN KEY (proxy_id) REFERENCES prc_proxies(id) ON DELETE CASCADE`
- `prc_proxy_targets_proxy_id_target_unique`: `UNIQUE (proxy_id, target)`

Indexes:
- `prc_proxy_targets_pkey`: `CREATE UNIQUE INDEX prc_proxy_targets_pkey ON public.prc_proxy_targets USING btree (id)`
- `prc_proxy_targets_proxy_id_target_unique`: `CREATE UNIQUE INDEX prc_proxy_targets_proxy_id_target_unique ON public.prc_proxy_targets USING btree (proxy_id, target)`

#### prc_raw_artifacts

Owner: Procurement; retention: artifacts.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | character varying / varchar | NO | character varying(64) NOT NULL |
| disk | character varying / varchar | NO | character varying(255) NOT NULL |
| path | text / text | NO | text NOT NULL |
| last_seen_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |

Constraints:
- `prc_raw_artifacts_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_raw_artifacts_last_seen_at_index`: `CREATE INDEX prc_raw_artifacts_last_seen_at_index ON public.prc_raw_artifacts USING btree (last_seen_at)`
- `prc_raw_artifacts_pkey`: `CREATE UNIQUE INDEX prc_raw_artifacts_pkey ON public.prc_raw_artifacts USING btree (id)`

#### prc_registries

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| settings | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_registries_pkey`: `PRIMARY KEY (id)`
- `prc_registries_platform_id_code_unique`: `UNIQUE (platform_id, code)`
- `prc_registries_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`
- `prc_registries_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_registries_pkey`: `CREATE UNIQUE INDEX prc_registries_pkey ON public.prc_registries USING btree (id)`
- `prc_registries_platform_id_code_unique`: `CREATE UNIQUE INDEX prc_registries_platform_id_code_unique ON public.prc_registries USING btree (platform_id, code)`
- `prc_registries_uuid_unique`: `CREATE UNIQUE INDEX prc_registries_uuid_unique ON public.prc_registries USING btree (uuid)`

#### prc_revisions

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| revision_number | integer / int4 | NO | integer NOT NULL |
| source_edition | text / text | YES | text |
| change_reason | text / text | YES | text |
| snapshot | jsonb / jsonb | NO | jsonb NOT NULL |
| snapshot_hash | text / text | NO | text NOT NULL |
| source_updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| detected_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| crawler_run_id | bigint / int8 | YES | bigint |
| created_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_revisions_crawler_run_id_foreign`: `FOREIGN KEY (crawler_run_id) REFERENCES prc_crawler_runs(id)`
- `prc_revisions_pkey`: `PRIMARY KEY (id, detected_at)`
- `prc_revisions_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`

Indexes:
- `prc_revisions_pkey`: `CREATE UNIQUE INDEX prc_revisions_pkey ON ONLY public.prc_revisions USING btree (id, detected_at)`
- `prc_revisions_procurement_id_index`: `CREATE INDEX prc_revisions_procurement_id_index ON ONLY public.prc_revisions USING btree (procurement_id)`
- `prc_revisions_procurement_revision_detected_unique`: `CREATE UNIQUE INDEX prc_revisions_procurement_revision_detected_unique ON ONLY public.prc_revisions USING btree (procurement_id, revision_number, detected_at)`

#### prc_settings

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| group | character varying / varchar | NO | character varying(255) NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| locked | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| payload | json / json | NO | json NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_settings_group_name_unique`: `UNIQUE ("group", name)`
- `prc_settings_pkey`: `PRIMARY KEY (id)`

Indexes:
- `prc_settings_group_name_unique`: `CREATE UNIQUE INDEX prc_settings_group_name_unique ON public.prc_settings USING btree ("group", name)`
- `prc_settings_pkey`: `CREATE UNIQUE INDEX prc_settings_pkey ON public.prc_settings USING btree (id)`

#### prc_source_circuits

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| stage | text / text | NO | text NOT NULL |
| enabled | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| manually_paused | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| failure_threshold | integer / int4 | YES | integer |
| layout_threshold | integer / int4 | YES | integer |
| retry_interval_minutes | integer / int4 | YES | integer |
| consecutive_failures | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| consecutive_layout_failures | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| consecutive_skips | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| retry_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| held_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| last_failure_kind | text / text | YES | text |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| changed_by | text / text | YES | text |
| created_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |

Constraints:
- `prc_source_circuits_pkey`: `PRIMARY KEY (id)`
- `prc_source_circuits_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`
- `prc_source_circuits_platform_id_stage_unique`: `UNIQUE (platform_id, stage)`

Indexes:
- `prc_source_circuits_pkey`: `CREATE UNIQUE INDEX prc_source_circuits_pkey ON public.prc_source_circuits USING btree (id)`
- `prc_source_circuits_platform_id_stage_unique`: `CREATE UNIQUE INDEX prc_source_circuits_platform_id_stage_unique ON public.prc_source_circuits USING btree (platform_id, stage)`

#### prc_source_incidents

Owner: Procurement; retention: incidents.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| crawler_id | bigint / int8 | YES | bigint |
| run_id | bigint / int8 | YES | bigint |
| fetch_id | bigint / int8 | YES | bigint |
| type | text / text | NO | text NOT NULL |
| severity | text / text | NO | text NOT NULL |
| failure_kind | text / text | YES | text |
| http_status | integer / int4 | YES | integer |
| cursor | jsonb / jsonb | YES | jsonb |
| request_params | jsonb / jsonb | YES | jsonb |
| message | text / text | NO | text NOT NULL |
| context | jsonb / jsonb | YES | jsonb |
| actor | text / text | YES | text |
| acknowledged_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| acknowledged_by | text / text | YES | text |
| created_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL |

Constraints:
- `prc_source_incidents_crawler_id_foreign`: `FOREIGN KEY (crawler_id) REFERENCES prc_crawlers(id) ON DELETE SET NULL`
- `prc_source_incidents_pkey`: `PRIMARY KEY (id)`
- `prc_source_incidents_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`

Indexes:
- `prc_source_incidents_pkey`: `CREATE UNIQUE INDEX prc_source_incidents_pkey ON public.prc_source_incidents USING btree (id)`
- `prc_source_incidents_platform_id_created_at_index`: `CREATE INDEX prc_source_incidents_platform_id_created_at_index ON public.prc_source_incidents USING btree (platform_id, created_at)`
- `prc_source_incidents_run_id_index`: `CREATE INDEX prc_source_incidents_run_id_index ON public.prc_source_incidents USING btree (run_id)`
- `prc_source_incidents_type_created_at_index`: `CREATE INDEX prc_source_incidents_type_created_at_index ON public.prc_source_incidents USING btree (type, created_at)`

#### prc_status_mappings

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| registry_id | bigint / int8 | YES | bigint |
| source_stage | character varying / varchar | NO | character varying(255) NOT NULL |
| canonical_status | character varying / varchar | NO | character varying(255) NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| platform_id | bigint / int8 | YES | bigint |

Constraints:
- `prc_status_mappings_exactly_one_applicability_check`: `CHECK (((platform_id IS NOT NULL) <> (registry_id IS NOT NULL)))`
- `prc_status_mappings_pkey`: `PRIMARY KEY (id)`
- `prc_status_mappings_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`
- `prc_status_mappings_registry_id_foreign`: `FOREIGN KEY (registry_id) REFERENCES prc_registries(id) ON DELETE CASCADE`

Indexes:
- `prc_status_mappings_pkey`: `CREATE UNIQUE INDEX prc_status_mappings_pkey ON public.prc_status_mappings USING btree (id)`
- `prc_status_mappings_platform_source_stage_unique`: `CREATE UNIQUE INDEX prc_status_mappings_platform_source_stage_unique ON public.prc_status_mappings USING btree (platform_id, source_stage) WHERE (platform_id IS NOT NULL)`
- `prc_status_mappings_registry_source_stage_unique`: `CREATE UNIQUE INDEX prc_status_mappings_registry_source_stage_unique ON public.prc_status_mappings USING btree (registry_id, source_stage) WHERE (registry_id IS NOT NULL)`

#### pulse_aggregates

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| bucket | integer / int4 | NO | integer NOT NULL |
| period | integer / int4 | NO | integer NOT NULL |
| type | character varying / varchar | NO | character varying(255) NOT NULL |
| key | text / text | NO | text NOT NULL |
| key_hash | uuid / uuid | NO | uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL |
| aggregate | character varying / varchar | NO | character varying(255) NOT NULL |
| value | numeric / numeric | NO | numeric(20,2) NOT NULL |
| count | integer / int4 | YES | integer |

Constraints:
- `pulse_aggregates_bucket_period_type_aggregate_key_hash_unique`: `UNIQUE (bucket, period, type, aggregate, key_hash)`
- `pulse_aggregates_pkey`: `PRIMARY KEY (id)`

Indexes:
- `pulse_aggregates_bucket_period_type_aggregate_key_hash_unique`: `CREATE UNIQUE INDEX pulse_aggregates_bucket_period_type_aggregate_key_hash_unique ON public.pulse_aggregates USING btree (bucket, period, type, aggregate, key_hash)`
- `pulse_aggregates_period_bucket_index`: `CREATE INDEX pulse_aggregates_period_bucket_index ON public.pulse_aggregates USING btree (period, bucket)`
- `pulse_aggregates_period_type_aggregate_bucket_index`: `CREATE INDEX pulse_aggregates_period_type_aggregate_bucket_index ON public.pulse_aggregates USING btree (period, type, aggregate, bucket)`
- `pulse_aggregates_pkey`: `CREATE UNIQUE INDEX pulse_aggregates_pkey ON public.pulse_aggregates USING btree (id)`
- `pulse_aggregates_type_index`: `CREATE INDEX pulse_aggregates_type_index ON public.pulse_aggregates USING btree (type)`

#### pulse_entries

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| timestamp | integer / int4 | NO | integer NOT NULL |
| type | character varying / varchar | NO | character varying(255) NOT NULL |
| key | text / text | NO | text NOT NULL |
| key_hash | uuid / uuid | NO | uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL |
| value | bigint / int8 | YES | bigint |

Constraints:
- `pulse_entries_pkey`: `PRIMARY KEY (id)`

Indexes:
- `pulse_entries_key_hash_index`: `CREATE INDEX pulse_entries_key_hash_index ON public.pulse_entries USING btree (key_hash)`
- `pulse_entries_pkey`: `CREATE UNIQUE INDEX pulse_entries_pkey ON public.pulse_entries USING btree (id)`
- `pulse_entries_timestamp_index`: `CREATE INDEX pulse_entries_timestamp_index ON public.pulse_entries USING btree ("timestamp")`
- `pulse_entries_timestamp_type_key_hash_value_index`: `CREATE INDEX pulse_entries_timestamp_type_key_hash_value_index ON public.pulse_entries USING btree ("timestamp", type, key_hash, value)`
- `pulse_entries_type_index`: `CREATE INDEX pulse_entries_type_index ON public.pulse_entries USING btree (type)`

#### pulse_values

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| timestamp | integer / int4 | NO | integer NOT NULL |
| type | character varying / varchar | NO | character varying(255) NOT NULL |
| key | text / text | NO | text NOT NULL |
| key_hash | uuid / uuid | NO | uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL |
| value | text / text | NO | text NOT NULL |

Constraints:
- `pulse_values_pkey`: `PRIMARY KEY (id)`
- `pulse_values_type_key_hash_unique`: `UNIQUE (type, key_hash)`

Indexes:
- `pulse_values_pkey`: `CREATE UNIQUE INDEX pulse_values_pkey ON public.pulse_values USING btree (id)`
- `pulse_values_timestamp_index`: `CREATE INDEX pulse_values_timestamp_index ON public.pulse_values USING btree ("timestamp")`
- `pulse_values_type_index`: `CREATE INDEX pulse_values_type_index ON public.pulse_values USING btree (type)`
- `pulse_values_type_key_hash_unique`: `CREATE UNIQUE INDEX pulse_values_type_key_hash_unique ON public.pulse_values USING btree (type, key_hash)`

#### ref_kbk

Owner: Reference; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| parent_code | character varying / varchar | YES | character varying(255) |
| level | integer / int4 | NO | integer NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| valid_from | date / date | NO | date NOT NULL |
| valid_to | date / date | YES | date |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `ref_kbk_code_unique`: `UNIQUE (code)`
- `ref_kbk_pkey`: `PRIMARY KEY (id)`

Indexes:
- `ref_kbk_code_unique`: `CREATE UNIQUE INDEX ref_kbk_code_unique ON public.ref_kbk USING btree (code)`
- `ref_kbk_parent_code_index`: `CREATE INDEX ref_kbk_parent_code_index ON public.ref_kbk USING btree (parent_code)`
- `ref_kbk_pkey`: `CREATE UNIQUE INDEX ref_kbk_pkey ON public.ref_kbk USING btree (id)`

#### ref_ktru

Owner: Reference; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| parent_code | character varying / varchar | YES | character varying(255) |
| level | integer / int4 | NO | integer NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| okpd2_code | character varying / varchar | YES | character varying(255) |
| valid_from | date / date | NO | date NOT NULL |
| valid_to | date / date | YES | date |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `ref_ktru_code_unique`: `UNIQUE (code)`
- `ref_ktru_pkey`: `PRIMARY KEY (id)`

Indexes:
- `ref_ktru_code_unique`: `CREATE UNIQUE INDEX ref_ktru_code_unique ON public.ref_ktru USING btree (code)`
- `ref_ktru_parent_code_index`: `CREATE INDEX ref_ktru_parent_code_index ON public.ref_ktru USING btree (parent_code)`
- `ref_ktru_pkey`: `CREATE UNIQUE INDEX ref_ktru_pkey ON public.ref_ktru USING btree (id)`

#### ref_okpd2

Owner: Reference; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| parent_code | character varying / varchar | YES | character varying(255) |
| level | integer / int4 | NO | integer NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| valid_from | date / date | NO | date NOT NULL |
| valid_to | date / date | YES | date |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `ref_okpd2_code_unique`: `UNIQUE (code)`
- `ref_okpd2_pkey`: `PRIMARY KEY (id)`

Indexes:
- `ref_okpd2_code_unique`: `CREATE UNIQUE INDEX ref_okpd2_code_unique ON public.ref_okpd2 USING btree (code)`
- `ref_okpd2_parent_code_index`: `CREATE INDEX ref_okpd2_parent_code_index ON public.ref_okpd2 USING btree (parent_code)`
- `ref_okpd2_pkey`: `CREATE UNIQUE INDEX ref_okpd2_pkey ON public.ref_okpd2 USING btree (id)`

#### ref_okved2

Owner: Reference; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| code | character varying / varchar | NO | character varying(255) NOT NULL |
| parent_code | character varying / varchar | YES | character varying(255) |
| level | integer / int4 | NO | integer NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| valid_from | date / date | NO | date NOT NULL |
| valid_to | date / date | YES | date |
| attributes | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `ref_okved2_code_unique`: `UNIQUE (code)`
- `ref_okved2_pkey`: `PRIMARY KEY (id)`

Indexes:
- `ref_okved2_code_unique`: `CREATE UNIQUE INDEX ref_okved2_code_unique ON public.ref_okved2 USING btree (code)`
- `ref_okved2_parent_code_index`: `CREATE INDEX ref_okved2_parent_code_index ON public.ref_okved2 USING btree (parent_code)`
- `ref_okved2_pkey`: `CREATE UNIQUE INDEX ref_okved2_pkey ON public.ref_okved2 USING btree (id)`

#### roles

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| class_name | character varying / varchar | YES | character varying(255) |
| level | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `roles_name_unique`: `UNIQUE (name)`
- `roles_pkey`: `PRIMARY KEY (id)`

Indexes:
- `roles_name_unique`: `CREATE UNIQUE INDEX roles_name_unique ON public.roles USING btree (name)`
- `roles_pkey`: `CREATE UNIQUE INDEX roles_pkey ON public.roles USING btree (id)`

#### sessions

Owner: Framework/vendor; retention: runtime.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | character varying / varchar | NO | character varying(255) NOT NULL |
| user_id | bigint / int8 | YES | bigint |
| ip_address | character varying / varchar | YES | character varying(45) |
| user_agent | text / text | YES | text |
| payload | text / text | NO | text NOT NULL |
| last_activity | integer / int4 | NO | integer NOT NULL |

Constraints:
- `sessions_pkey`: `PRIMARY KEY (id)`

Indexes:
- `sessions_last_activity_index`: `CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity)`
- `sessions_pkey`: `CREATE UNIQUE INDEX sessions_pkey ON public.sessions USING btree (id)`
- `sessions_user_id_index`: `CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id)`

#### users

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| name | character varying / varchar | NO | character varying(255) NOT NULL |
| email | character varying / varchar | NO | character varying(255) NOT NULL |
| email_verified_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| password | character varying / varchar | NO | character varying(255) NOT NULL |
| remember_token | character varying / varchar | YES | character varying(100) |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `users_email_unique`: `UNIQUE (email)`
- `users_pkey`: `PRIMARY KEY (id)`

Indexes:
- `users_email_unique`: `CREATE UNIQUE INDEX users_email_unique ON public.users USING btree (email)`
- `users_pkey`: `CREATE UNIQUE INDEX users_pkey ON public.users USING btree (id)`

<!-- schema-inventory:end -->

## Передача P1.4 → P1.6–P1.12

Inventory выше остаётся декларацией целевой схемы, подготовленной P1.1. P1.4 сверяет её с PHP-контрактами P1.2 и изолированным стендом P1.3. Новых migrations, Process tables, admission, handlers или production consumers этот пункт не включает. Существующие consumers работают до своего owning cutover.

### Что уже проверяется

| Контракт | Assertions текущего среза |
|---|---|
| Девять `WorkState` | `SchemaContractTest` сравнивает PHP enum, inventory conventions и значения SQL CHECK; `partial`, `recovered`, `stale`, `duplicate_delivery` не принимаются как состояния work. |
| Восемь `StageCode` | Тот же тест сверяет полный enum с inventory. CHECK четырёх stage-bearing carriers P1.6 допускают только listing/detail/status_refresh/download; production activation пуста. |
| Реальные subject owners | Для каждой стадии проверяются `SubjectKind`, owner column, bigint/UUID и owning FK stage. Для extraction owner — `analysis_window_id`; `ExtractionWindowInput` хранит analysis run + window key, разрешение реального window принадлежит будущему resolver. |
| Input/outcome codecs | `ProcessCodecTest` проверяет восемь input variants, canonical encoding/work identity, пять outcome variants и отказы при неверных schema/version/keys/types. Это pure contracts, без DB claim/finalize. |
| Recovery carrier | `CheckpointCodecTest` проверяет envelope v1, сочетания mode/purpose/receipt, сохранение pinned plan при progress checkpoint и отказ при чужом input hash. Наличие typed receipt reference не доказывает проверку object bytes или финансовое settlement. |
| Architecture и inventory | T44 содержит отрицательные source fixtures; `test_schema_inventory.py` — отрицательные случаи DDL/FK/staging/catalog drift. Generated inventory обновляется только из его owning JSON. |
| Isolated reset | Identity проверяется до destructive operation; readiness — после migrations. Полный reset пересоздаёт только guarded test DB вместе с pg_partman metadata. Два install, сравнение schema/seed/catalog и network egress denial проверяет conductor отдельными acceptance commands. |

`tests/Unit/ProcessCore/SchemaContractTest.php` связывает inventory с исполняемыми PHP enums и subject types. Сам inventory и его generated section не являются доказательством наличия новых таблиц. Неактивные будущие stages в codec не дают разрешения запускать handler.

### Обязательный вход следующей группы

| Пункт | Граница работы |
|---|---|
| P1.6 | Создать initial Process baseline в dependency order с реальными crawl subjects/FK, initial четырьмя schema-supported stages, handler holds/incidents, daemon heartbeats и полями provider recovery/fault policy. Добавить subject generation/cycle columns в owning baseline migrations. Проверить два isolated clean install. |
| P1.7–P1.8 | Реализовать зарегистрированные codec/subject/handler seams и fenced primitives. Полный lock prefix, immutable input и exact admission membership проверяются на настоящем PostgreSQL. |
| P1.9 | Отдельный независимый review своего assigned scope; наличие этого handoff не заменяет review. |
| P1.10 | Подключить scalar-only queue delivery, outbox и daemons с fixture handlers. Redis остаётся доставкой; DB — authority. |
| P1.11 | Доказать recovery, stale owner denial и отдельный periodic blocked-provider scan на реальной DB с fixture resolver. |
| P1.12 | Выполнить owning acceptance группы, сохранив candidate-bound evidence; legacy domain callers переключаются позже своими owning пунктами. |

При переносе inventory в migrations сохраняются все positive schema-version CHECK и nullable JSON/version pairs, CHECK owner tuple, девять состояний, due/lease/claim/provider-recovery constraints и partial indexes. Future owner columns не получают FK на ещё не созданную таблицу: document version FK добавляется P3.1, analysis window/run FK — P3.8. `ON DELETE CASCADE` ограничен двумя composition-child исключениями, перечисленными выше.

Recovery использует только `WorkCheckpointEnvelope`: stage progress и pinned provider plan сохраняются совместно. `provider_result_unknown` требует exact logical/physical linkage и отдельного `provider_recovery_due_at`; обычный `due_at` остаётся NULL. Будущий reconcile tick обслуживает blocked category каждые 5 s, batch ≤50, без новой queue delivery; отсутствие receipt переносит reconciliation на DBnow+30 s и не разрешает новый send. P1 использует fixture resolver, реальная provider mapping принадлежит P3.8–P3.14.

Следующая группа также обязана проверить per-work handler fault limit отдельно от общего version-scoped hold: третий programming fault блокирует work без item failure, четвёртая delivery не вызывает handler/send. Pure enum/codec assertions не доказывают эти races, atomic journal/outbox, lease fencing, контроль паузы или устойчивость к Redis loss. T03/T06/T08/T09/T15/T22/T24/T25/T31 получают runtime assertions в owning execution/concurrency suites; будущие targets остаются `suite_not_implemented` до появления своих assertions.

Для передачи нужны actual candidate HEAD/dirty digest, hashes product inputs/fixtures и результаты V1–V6 conductor. P1.4 не переносит green из предыдущего пункта. Полная existing no-TIA regression остаётся обязательной до cutover; этот документ не является заявлением о её выполнении.
