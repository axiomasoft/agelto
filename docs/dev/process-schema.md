---
audience: dev
type: reference
status: living
---

# Process schema: baseline, ownership и staged target

P1.1 фиксирует полный inventory; он не создаёт Process tables, handlers, очереди или runtime flags.
Исходный baseline P1.1: 81 project migration, 75 root tables, 939 columns. P1.6 устанавливает
свои 19 carriers и subject head/cycle columns: 83 migrations, 94 root tables, 1181 columns
(`current_item` = P1.6; раздел «Передача P1.6 → P1.7–P1.12» ниже). Partition children
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
AI package до swap. В final baseline P3.17 отсутствуют prc_source_incidents, prc_fetches и
prc_operation_logs; source circuits удалены P2.18. Journal/sidebar читают только Process ledger.

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
| proxyma | По правилам Proxyma. Пакетная очистка после 30 дней удаляет только завершённые записи до авторизации, сохраняя факты отправки и результаты с неопределённым исходом. |

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
| `2026_07_04_100001_create_prc_registries_table.php` | adapt | P2.14 | Canonical platform-scoped primary identity and single EIS extension writer baseline cutover. |
| `2026_07_04_100002_create_prc_status_mappings_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100005_create_ref_okpd2_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100006_create_ref_ktru_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100007_create_ref_okved2_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100008_create_ref_kbk_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100009_create_org_organizations_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100010_create_prc_procurement_parties_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100011_create_prc_procurements_table.php` | adapt_baseline | P2.18 | Atomic acquisition producer, profile, provenance and adapter owner cutover. |
| `2026_07_04_100012_create_prc_revisions_table.php` | adapt_baseline | P2.18 | Atomic acquisition producer, profile, provenance and adapter owner cutover. |
| `2026_07_04_100013_create_prc_events_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100014_create_prc_lots_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100015_create_prc_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100016_create_prc_financing_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100017_create_prc_eis_details_table.php` | adapt | P2.14 | Canonical platform-scoped primary identity and single EIS extension writer baseline cutover. |
| `2026_07_04_100018_create_prc_documents_table.php` | adapt_baseline | P2.18 | Atomic acquisition cutover; versioned source document reference descriptor. |
| `2026_07_04_100019_create_prc_document_versions_table.php` | rewrite-baseline | P3.5 | Complete canonical tuple/disk requirements, version bytea hash, immutable metadata and no legacy NULL tuple or partial uniqueness. |
| `2026_07_04_100020_create_prc_protocols_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100021_create_prc_bids_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100022_create_prc_awards_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100023_create_prc_clarifications_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100024_create_prc_cancellations_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100025_create_prc_ai_analyses_table.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_07_04_100027_create_llm_providers_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100028_create_llm_routes_table.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_07_04_100029_create_llm_prompts_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100030_create_prc_ai_dataset_examples_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100031_create_prc_ai_eval_runs_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100032_create_prc_ai_eval_results_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100033_create_prc_estimates_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100034_create_prc_estimate_sections_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100035_create_prc_estimate_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100036_create_prc_estimate_resources_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100037_create_prc_estimate_totals_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100038_create_prc_crawlers_table.php` | adapt_baseline | P2.18 | Atomic acquisition producer, profile, provenance and adapter owner cutover. |
| `2026_07_04_100040_create_prc_proxies_table.php` | adapt | P2.6 | Atomic network consumer, provider ownership and route configuration cutover. |
| `2026_07_04_100044_create_prc_ignored_items_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_04_100047_setup_pg_partman.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_07_09_000001_create_pulse_tables.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_09_100050_set_pg_partman_retention.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_07_09_100052_create_prc_ai_corrections_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_27_100053_create_prc_ai_fixtures_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_27_100055_create_prc_document_representations_table.php` | rewrite-baseline | P3.5 | Complete canonical tuple/disk requirements, version bytea hash, immutable metadata and no legacy NULL tuple or partial uniqueness. |
| `2026_07_27_100057_create_prc_estimate_work_types_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_07_28_100058_create_prc_ai_prompt_suggestions_table.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_08_05_100068_enable_db_observability_extensions.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_08_05_100074_precreate_historical_partitions.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_08_05_100076_prune_orphaned_partman_part_config_entries.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_08_07_100078_create_prc_okpd2_nodes_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_08_100085_create_prc_platform_checks_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_09_099999_create_prc_settings_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_08_09_100089_add_unprocessable_reason_to_prc_documents_table.php` | fold_into_create | P3.1 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_09_100090_add_archive_entry_path_to_prc_document_versions_table.php` | fold_into_create | P3.1 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_09_100091_migrate_screening_results_to_v2.php` | remove_after_clean_proof | P3.13 | P3.13 clean baseline keeps screening schema/indexes and removes legacy result backfill/restore writers; accepted provenance stays immutable. |
| `2026_08_09_100092_add_applicability_to_prc_status_mappings_table.php` | fold_into_create | P2.14 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_08_12_100092_add_screening_summary_search_index.php` | fold_index_into_create | P3.13 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_09_10_100093_repair_partition_coverage.php` | remove_after_clean_proof | P1.1–P1.4 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_09_12_100095_preserve_2025_procurement_events.php` | remove_after_clean_proof | P1.1–P1.4 | Кандидат на удаление только owning пунктом после доказанного clean install без repair; P1.1 не удаляет. |
| `2026_10_08_100101_verify_partition_bounds.php` | keep_infrastructure | baseline | Сохранить infrastructure; full DB reset удаляет partman metadata вне public. |
| `2026_10_08_100103_store_source_time_precision.php` | fold_into_create | P2.14 | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_10_08_100104_catalog_raw_artifacts.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_10_18_170000_add_eis_identity_to_org_organizations.php` | fold_into_create | Organization | Свернуть только в owning CREATE при coherent consumer cutover; P1.1 не меняет DDL. |
| `2026_10_23_100000_create_org_organization_identifiers_table.php` | keep | baseline | Существующий CREATE, без изменений в P1.1. |
| `2026_10_25_100000_create_prc_process_ledger_tables.php` | adapt_baseline | P3.8 | Atomic acquisition producer, profile, provenance and adapter owner cutover. |
| `2026_10_25_100000_create_prc_process_network_tables.php` | adapt_baseline | P2.18 | Atomic acquisition producer, profile, provenance and adapter owner cutover. |
| `2026_10_25_100001_create_prc_request_observation_tables.php` | create | P1.6 | P1.6 durable request attempt/observation carriers execution#s04; network FKs добавляет P2.1. |
| `2026_10_28_100000_create_prc_process_projection_tables.php` | create | P1.15 | P1.15 projection states, totals and receipts; exact isolated PostgreSQL catalog and schema captured. |
| `2026_10_28_100002_create_prc_acquisition_snapshot_tables.php` | create | P2.16 | Durable immutable detail observations and recurring status cycles; runtime binding remains P2.18. |
| `2026_10_31_100000_create_prc_acquisition_schedules_table.php` | create | P2.18 | Durable profile/kind schedule; allocation and next_due advancement share one transaction. |
| `2026_11_01_100000_create_prc_document_storage_tables.php` | rewrite-baseline | P3.5 | Complete canonical tuple/disk requirements, version bytea hash, immutable metadata and no legacy NULL tuple or partial uniqueness. |
| `2026_11_02_100000_create_prc_analysis_tables.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_11_02_100001_create_llm_request_ledger.php` | rewrite | P3.13 | Canonical AI recipe/chunk/publication and provider-ledger consumer cutover. |
| `2026_11_02_100002_create_llm_assets.php` | create | P3.12 | Canonical files/cache identity and fenced physical creation ownership; no paid runtime activation. |
| `2026_11_07_100000_add_llm_send_eligibility_indexes.php` | create | P3.17 | Forward send-eligibility indexes for period reservation state and unknown logical request state. |
| `2026_11_11_100000_create_prc_proxyma_adapter_links.php` | create | Proxyma:P9.2 | P10.3 Q1-approved getTable carrier imports only; original DDL and pinned baseline migrations retained. PLAN2:P2.4 adds rollback for the owned schema objects. |
| `2026_11_11_100001_create_prc_proxyma_transfer_records.php` | create | Proxyma:P10.1 | P10.3 Q1-approved getTable carrier imports only; original DDL and pinned baseline migrations retained. PLAN2:P2.4 adds rollback for the owned schema objects. |
| `2026_11_11_100002_add_prc_proxyma_cutover_fences.php` | alter | Proxyma:P10.2 | P10.3 Q1-approved getTable carrier imports only; original DDL and pinned baseline migrations retained. |
| `2026_11_25_100000_add_audit_performance_indexes.php` | alter | PLAN2:P2.4 | Add indexes for retention, diagnostics and operator queries with reversible DDL. |
| `2026_11_25_100001_add_document_repair_ownership.php` | alter | PLAN2:P3.1 | Persist the actual canonical repair attempt and fence recovery from newer repairs. |
| `2026_11_25_100002_add_attributes_observed_at_to_org_organizations.php` | alter | PLAN2:P5.3 | Record when organization attributes were observed so archive imports preserve newer data. |
| `2026_11_26_100000_tune_process_tables_autovacuum_and_fillfactor.php` | alter | PLAN1:P2.1 | Tune fillfactor and autovacuum for hot process queue tables with reversible reloptions. |
| `2026_11_28_100000_create_prc_parsing_rule_tables.php` | create | PARSING:W1 | Create declarative parsing rule sets, immutable rule versions and append-only audit events. |

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
| scope_kind | text | no | scope_kind text NOT NULL CHECK (scope_kind IN ('system','acquisition','documents','platform','provider','cohort','stage')) |
| scope_key | text | no | scope_key text NOT NULL |
| parent_id | uuid | yes | parent_id uuid NULL REFERENCES prc_control_scopes(id) → prc_control_scopes(id) ON DELETE NO ACTION @P1.6 |
| desired_mode | text | no | desired_mode text NOT NULL DEFAULT 'enabled' CHECK (desired_mode IN ('enabled','paused')) |
| control_version | bigint | no | control_version bigint NOT NULL DEFAULT 1 CHECK (control_version >= 1) |
| changed_by | bigint | yes | changed_by bigint NULL REFERENCES users(id) → users(id) ON DELETE NO ACTION @P1.6 |
| change_reason | text | yes | change_reason text NULL |
| changed_at | timestamptz | yes | changed_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_control_scopes_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES users(id)`
- `CONSTRAINT prc_control_scopes_control_version_check CHECK ((control_version >= 1))`
- `CONSTRAINT prc_control_scopes_desired_mode_check CHECK ((desired_mode = ANY (ARRAY['enabled'::text, 'paused'::text])))`
- `CONSTRAINT prc_control_scopes_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES prc_control_scopes(id)`
- `CONSTRAINT prc_control_scopes_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_control_scopes_root_check CHECK (((scope_kind = 'system'::text) = (parent_id IS NULL)))`
- `CONSTRAINT prc_control_scopes_scope_key_unique UNIQUE (scope_key)`
- `CONSTRAINT prc_control_scopes_scope_kind_check CHECK ((scope_kind = ANY (ARRAY['system'::text, 'acquisition'::text, 'documents'::text, 'platform'::text, 'provider'::text, 'cohort'::text, 'stage'::text])))`

Indexes:
- `CREATE UNIQUE INDEX prc_control_scopes_pkey ON public.prc_control_scopes USING btree (id)`
- `CREATE UNIQUE INDEX prc_control_scopes_scope_key_unique ON public.prc_control_scopes USING btree (scope_key)`

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
- `CREATE INDEX prc_process_runs_requested_idx ON public.prc_process_runs USING btree (requested_at DESC)`

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
- `CREATE INDEX prc_process_incidents_state_seen_idx ON public.prc_process_incidents USING btree (state, last_seen_at DESC)`

#### prc_process_handler_holds

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')) |
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
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')) |
| subject_kind | text | no | subject_kind text NOT NULL |
| subject_id | text | no | subject_id text NOT NULL |
| semantic_policy_hash | bytea | no | semantic_policy_hash bytea NOT NULL CHECK (octet_length(semantic_policy_hash)=32) |
| access_policy_hash | bytea | no | access_policy_hash bytea NOT NULL CHECK (octet_length(access_policy_hash)=32) |
| cycle_seq | bigint | no | cycle_seq bigint NOT NULL DEFAULT 1 CHECK (cycle_seq >= 1) |
| expected_head_generation | bigint | no | expected_head_generation bigint NOT NULL CHECK (expected_head_generation >= 1) |
| state | text | no | state text NOT NULL CHECK (state IN ('pending','active','terminal')) |
| pending_reason | text | yes | pending_reason text NULL CHECK (pending_reason IS NULL OR (state='pending' AND pending_reason='handler_not_activated')) |
| requested_at | timestamptz | no | requested_at timestamptz NOT NULL |
| next_due_at | timestamptz | yes | next_due_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_operation_intents_intent_key_unique UNIQUE (intent_key)`

Indexes:
- `CREATE UNIQUE INDEX prc_operation_intents_one_active_unique ON prc_operation_intents (stage, subject_kind, subject_id) WHERE state = 'active';`
- `CREATE INDEX prc_operation_intents_state_due_idx ON public.prc_operation_intents USING btree (state, next_due_at)`

#### prc_process_work

Create: P1.6; activation boundary: P1.7; owner: `Repositories/Procurement/Process`; retention: authority. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid PRIMARY KEY |
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')) |
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
- `CREATE INDEX prc_process_work_updated_idx ON public.prc_process_work USING btree (updated_at DESC)`
- `CREATE INDEX prc_process_work_detail_latest_idx ON public.prc_process_work USING btree (procurement_id, created_at DESC, id DESC) WHERE ((stage = 'detail'::text) AND (procurement_id IS NOT NULL))`

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
| adapter_health_id | uuid | yes | adapter_health_id uuid NULL REFERENCES prc_adapter_health(id) → prc_adapter_health(id) ON DELETE NO ACTION @P2.18 |
| adapter_health_epoch | bigint | yes | adapter_health_epoch bigint NULL CHECK(adapter_health_epoch IS NULL OR adapter_health_epoch >= 1) |

Constraints:
- `CONSTRAINT prc_process_attempts_work_generation_unique UNIQUE (work_id, generation)`
- `CONSTRAINT prc_attempt_work_pair UNIQUE (id, work_id)`
- `CONSTRAINT prc_process_attempts_adapter_pair CHECK((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL))`

Indexes:
- `CREATE INDEX prc_process_attempts_fault_idx ON prc_process_attempts (handler_version, fault_signature, finished_at) WHERE fault_signature IS NOT NULL;`
- `CREATE INDEX prc_process_attempts_work_fault_idx ON prc_process_attempts(work_id,handler_version) WHERE fault_signature IS NOT NULL AND finished_at IS NOT NULL;`
- `CREATE INDEX prc_process_attempts_fault_policy_pending_idx ON prc_process_attempts (finished_at,id) WHERE fault_signature IS NOT NULL AND fault_policy_checked_at IS NULL;`
- `CREATE INDEX prc_process_attempts_adapter_live ON prc_process_attempts(adapter_health_id, adapter_health_epoch) WHERE finished_at IS NULL AND adapter_health_id IS NOT NULL`

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
| stage | text | no | stage text NOT NULL CHECK (stage IN ('listing','detail','status_refresh','download','prepare','screening','extraction','publication')) |
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
- `CREATE INDEX prc_process_run_work_work_idx ON public.prc_process_run_work USING btree (work_id)`

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
- `CREATE INDEX prc_process_transitions_occurred_idx ON public.prc_process_transitions USING btree (occurred_at, event_id)`
- `CREATE INDEX prc_process_transitions_work_state_time_idx ON public.prc_process_transitions USING btree (entity_kind, new_state, occurred_at) WHERE (entity_kind = 'work'::text)`

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
- `CREATE INDEX prc_process_outbox_published_idx ON public.prc_process_outbox USING btree (published_at, message_id) WHERE (published_at IS NOT NULL)`

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
| provider_key | text | yes | provider_key text NULL CHECK(provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$') |
| adapter_health_id | uuid | yes | adapter_health_id uuid NULL REFERENCES prc_adapter_health(id) → prc_adapter_health(id) ON DELETE NO ACTION @P2.6 |
| adapter_health_epoch | bigint | yes | adapter_health_epoch bigint NULL CHECK(adapter_health_epoch >= 1) |
| parent_attempt_id | uuid | yes | parent_attempt_id uuid NULL REFERENCES prc_process_attempts(id) CHECK(parent_attempt_id IS NULL OR kind='probe') → prc_process_attempts(id) ON DELETE NO ACTION @P2.18 |
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
- `CONSTRAINT prc_outbound_operations_adapter_health_epoch_check CHECK (((adapter_health_epoch IS NULL) OR (adapter_health_epoch >= 1)))`
- `CONSTRAINT prc_outbound_operations_adapter_health_id_fkey FOREIGN KEY (adapter_health_id) REFERENCES prc_adapter_health(id)`
- `CONSTRAINT prc_outbound_operations_check CHECK (((platform_id IS NULL) OR (provider_key IS NULL)))`
- `CONSTRAINT prc_outbound_operations_check1 CHECK (((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL)))`
- `CONSTRAINT prc_outbound_operations_check2 CHECK ((lease_until <= deadline_at))`
- `CONSTRAINT prc_outbound_operations_check3 CHECK (((kind = 'probe'::text) = (probe_token IS NOT NULL)))`
- `CONSTRAINT prc_outbound_operations_check4 CHECK (((state = 'open'::text) = (finished_at IS NULL)))`
- `CONSTRAINT prc_outbound_operations_generation_check CHECK ((generation >= 1))`
- `CONSTRAINT prc_outbound_operations_kind_check CHECK ((kind = ANY (ARRAY['legacy_bridge'::text, 'diagnostic'::text, 'probe'::text])))`
- `CONSTRAINT prc_outbound_operations_operation_key_key UNIQUE (operation_key)`
- `CONSTRAINT prc_outbound_operations_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_outbound_operations_platform_id_fkey FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `CONSTRAINT prc_outbound_operations_policy_hash_check CHECK ((octet_length(policy_hash) = 32))`
- `CONSTRAINT prc_outbound_operations_provider_key_check CHECK (((provider_key IS NULL) OR (provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$'::text)))`
- `CONSTRAINT prc_outbound_operations_state_check CHECK ((state = ANY (ARRAY['open'::text, 'closed'::text, 'expired'::text])))`

Indexes:
- `CREATE INDEX prc_outbound_operations_adapter_live ON public.prc_outbound_operations USING btree (adapter_health_id, adapter_health_epoch, lease_until) WHERE ((state = 'open'::text) AND (adapter_health_id IS NOT NULL))`
- `CREATE UNIQUE INDEX prc_outbound_operations_operation_key_key ON public.prc_outbound_operations USING btree (operation_key)`
- `CREATE UNIQUE INDEX prc_outbound_operations_pkey ON public.prc_outbound_operations USING btree (id)`
- `CREATE INDEX prc_outbound_operations_reap ON public.prc_outbound_operations USING btree (lease_until) WHERE (state = 'open'::text)`

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
- `CONSTRAINT prc_network_demands_attempt_id_work_id_fkey FOREIGN KEY (attempt_id, work_id) REFERENCES prc_process_attempts(id, work_id)`
- `CONSTRAINT prc_network_demands_check CHECK ((((work_id IS NOT NULL) AND (attempt_id IS NOT NULL) AND (work_generation IS NOT NULL) AND (work_generation >= 1) AND (operation_id IS NULL) AND (operation_generation IS NULL)) OR ((work_id IS NULL) AND (attempt_id IS NULL) AND (work_generation IS NULL) AND (operation_id IS NOT NULL) AND (operation_generation IS NOT NULL) AND (operation_generation >= 1))))`
- `CONSTRAINT prc_network_demands_check1 CHECK ((expires_at <= operation_deadline))`
- `CONSTRAINT prc_network_demands_operation_id_fkey FOREIGN KEY (operation_id) REFERENCES prc_outbound_operations(id)`
- `CONSTRAINT prc_network_demands_origin_budget_id_fkey FOREIGN KEY (origin_budget_id) REFERENCES prc_network_budgets(id)`
- `CONSTRAINT prc_network_demands_physical_request_id_key UNIQUE (physical_request_id)`
- `CONSTRAINT prc_network_demands_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_network_demands_purpose_check CHECK ((purpose = ANY (ARRAY['ordinary'::text, 'probe'::text, 'diagnostic'::text])))`
- `CONSTRAINT prc_network_demands_route_id_fkey FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `CONSTRAINT prc_network_demands_state_check CHECK ((state = ANY (ARRAY['queued'::text, 'granted'::text, 'cancelled'::text, 'expired'::text])))`
- `CONSTRAINT prc_network_demands_target_id_fkey FOREIGN KEY (target_id) REFERENCES prc_source_targets(id)`
- `CONSTRAINT prc_network_demands_ticket_seq_key UNIQUE (ticket_seq)`
- `CONSTRAINT prc_network_demands_traffic_class_check CHECK ((traffic_class = ANY (ARRAY['fresh_listing'::text, 'status_refresh'::text, 'detail'::text, 'archive_listing'::text, 'download'::text, 'reference_sync'::text, 'diagnostic'::text, 'provider'::text])))`
- `CONSTRAINT prc_network_demands_work_id_fkey FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `CREATE UNIQUE INDEX prc_network_demands_attempt_active ON public.prc_network_demands USING btree (attempt_id) WHERE ((state = 'queued'::text) AND (attempt_id IS NOT NULL))`
- `CREATE INDEX prc_network_demands_class ON public.prc_network_demands USING btree (origin_budget_id, traffic_class, id)`
- `CREATE INDEX prc_network_demands_fifo ON public.prc_network_demands USING btree (origin_budget_id, ticket_seq) WHERE (state = 'queued'::text)`
- `CREATE UNIQUE INDEX prc_network_demands_operation_active ON public.prc_network_demands USING btree (operation_id) WHERE ((state = 'queued'::text) AND (operation_id IS NOT NULL))`
- `CREATE UNIQUE INDEX prc_network_demands_physical_request_id_key ON public.prc_network_demands USING btree (physical_request_id)`
- `CREATE UNIQUE INDEX prc_network_demands_pkey ON public.prc_network_demands USING btree (id)`
- `CREATE UNIQUE INDEX prc_network_demands_ticket_seq_key ON public.prc_network_demands USING btree (ticket_seq)`

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
| attempt_id | uuid | yes | attempt_id uuid NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P1.6 |
| demand_id | uuid | yes | demand_id uuid NULL UNIQUE REFERENCES prc_network_demands(id) → prc_network_demands(id) ON DELETE NO ACTION @P2.1 |
| request_seq | bigint | no | request_seq bigint NOT NULL CHECK(request_seq>=1) |
| target_id | uuid | yes | target_id uuid NULL REFERENCES prc_source_targets(id) → prc_source_targets(id) ON DELETE NO ACTION @P2.1 |
| route_id | uuid | yes | route_id uuid NULL REFERENCES prc_network_routes(id) → prc_network_routes(id) ON DELETE NO ACTION @P2.1 |
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
- `CHECK ((attempt_id IS NOT NULL) <> (demand_id IS NOT NULL))`

Indexes:
- `CREATE INDEX prc_request_attempts_started_idx ON public.prc_request_attempts USING btree (started_at)`

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
- `CREATE INDEX prc_observations_attempt_idx ON public.prc_observations USING btree (attempt_id)`
- `CREATE INDEX prc_observations_occurred_idx ON public.prc_observations USING btree (occurred_at)`

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

Create: P1.15; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

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

Create: P1.15; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| projector_key | text | no | projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key) → prc_process_projection_states(projector_key) ON DELETE NO ACTION @P1.15 |
| generation | bigint | no | generation bigint NOT NULL CHECK(generation>0) |
| metric_key | text | no | metric_key text NOT NULL |
| dimension_key | text | no | dimension_key text NOT NULL |
| value | bigint | no | value bigint NOT NULL CHECK(value>=0) |
| updated_at | timestamptz | no | updated_at timestamptz NOT NULL |

Constraints:
- `PRIMARY KEY(projector_key,generation,metric_key,dimension_key)`

Indexes:

#### prc_process_projection_receipts

Create: P1.15; activation boundary: P1.15; owner: `Repositories/Procurement/Process`; retention: projection. Contract: observability#s08.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| projector_key | text | no | projector_key text NOT NULL REFERENCES prc_process_projection_states(projector_key) → prc_process_projection_states(projector_key) ON DELETE NO ACTION @P1.15 |
| event_id | uuid | no | event_id uuid NOT NULL REFERENCES prc_process_transitions(event_id) ON DELETE RESTRICT ON DELETE RESTRICT → prc_process_transitions(event_id) ON DELETE RESTRICT @P1.15 |
| applied_at | timestamptz | no | applied_at timestamptz NOT NULL |

Constraints:
- `PRIMARY KEY(projector_key,event_id)`

Indexes:
- `CREATE INDEX prc_process_projection_receipts_event_idx ON prc_process_projection_receipts(event_id)`

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
| platform_id | bigint | yes | platform_id bigint NULL REFERENCES prc_platforms(id) → prc_platforms(id) ON DELETE NO ACTION @P2.1 |
| provider_key | text | yes | provider_key text NULL CHECK(provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$') |
| target_key | text | no | target_key text NOT NULL |
| canonical_origin | text | no | canonical_origin text NOT NULL |
| capability | text | no | capability text NOT NULL |
| endpoint_policy | jsonb | no | endpoint_policy jsonb NOT NULL |
| endpoint_policy_schema_version | smallint | no | endpoint_policy_schema_version smallint NOT NULL |
| policy_version | bigint | no | policy_version bigint NOT NULL CHECK(policy_version>=1) |
| retired_at | timestamptz | yes | retired_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_source_targets_check CHECK ((((platform_id IS NOT NULL) AND (provider_key IS NULL)) OR ((platform_id IS NULL) AND (provider_key IS NOT NULL) AND (provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$'::text))))`
- `CONSTRAINT prc_source_targets_endpoint_policy_schema_version_check CHECK ((endpoint_policy_schema_version > 0))`
- `CONSTRAINT prc_source_targets_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_source_targets_platform_id_fkey FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `CONSTRAINT prc_source_targets_platform_id_target_key_key UNIQUE (platform_id, target_key)`
- `CONSTRAINT prc_source_targets_policy_version_check CHECK ((policy_version >= 1))`
- `CONSTRAINT prc_source_targets_provider_key_target_key_key UNIQUE (provider_key, target_key)`

Indexes:
- `CREATE UNIQUE INDEX prc_source_targets_pkey ON public.prc_source_targets USING btree (id)`
- `CREATE UNIQUE INDEX prc_source_targets_platform_id_target_key_key ON public.prc_source_targets USING btree (platform_id, target_key)`
- `CREATE UNIQUE INDEX prc_source_targets_provider_key_target_key_key ON public.prc_source_targets USING btree (provider_key, target_key)`

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

Create: P2.16; activation boundary: P2.18; owner: `Repositories/Procurement/Crawler`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

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
- `CHECK (result_ref_schema_version = 1)`
- `CHECK (completeness IN ('complete','complete_empty','partial','unavailable','not_applicable'))`
- `CHECK (char_length(part_key) BETWEEN 1 AND 160)`
- `CHECK (jsonb_typeof(result_ref) = 'object')`

Indexes:
- `CREATE INDEX prc_detail_snapshots_published_idx ON public.prc_detail_snapshots USING btree (procurement_id, created_at DESC) WHERE ((completeness = 'complete'::text) AND (part_key ~~ '%:published'::text))`

#### prc_status_refreshes

Create: P2.16; activation boundary: P2.18; owner: `Repositories/Procurement/Crawler`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

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
- `UNIQUE(intent_id)`

Indexes:
- `CREATE INDEX prc_status_refreshes_next_due_idx ON public.prc_status_refreshes USING btree (next_due_at)`

#### prc_procurement_documents

Create: P2.18; activation boundary: P2.18; owner: `Repositories/Procurement/Document`; retention: business. Contract: implementation-blueprint#bp-ddl-core.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| procurement_id | bigint | no | procurement_id bigint NOT NULL REFERENCES prc_procurements(id) → prc_procurements(id) ON DELETE NO ACTION @P2.14 |
| document_id | bigint | no | document_id bigint NOT NULL REFERENCES prc_documents(id) → prc_documents(id) ON DELETE NO ACTION @P2.14 |
| observed_at | timestamptz | no | observed_at timestamptz NOT NULL |
| removed_at | timestamptz | yes | removed_at timestamptz NULL |

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
| disk | text | no | disk text NOT NULL |
| object_key | text | no | object_key text NOT NULL |
| created_at | timestamptz | no | created_at timestamptz NOT NULL |
| repair_attempt_id | uuid | yes | repair_attempt_id uuid NULL REFERENCES prc_process_attempts(id) → prc_process_attempts(id) ON DELETE NO ACTION @P3.1 |
| repair_claimed_at | timestamptz | yes | repair_claimed_at timestamptz NULL |

Constraints:
- `CONSTRAINT prc_storage_reservations_owner_check CHECK ((work_id IS NULL)=(attempt_id IS NULL) AND NOT(work_id IS NOT NULL AND operation_id IS NOT NULL))`
- `CONSTRAINT prc_storage_reservations_cleanup_check CHECK ((status='cleaned')=(cleanup_verified_at IS NOT NULL))`
- `CONSTRAINT prc_storage_reservations_attempt_work_fk FOREIGN KEY(attempt_id,work_id) REFERENCES prc_process_attempts(id,work_id)`

Indexes:
- `CREATE INDEX prc_storage_reservations_active_idx ON prc_storage_reservations(domain_id) WHERE status IN ('reserved','writing','staged','cleaning')`

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
| screening_result | jsonb | yes | screening_result jsonb NULL |
| screening_result_schema_version | smallint | yes | screening_result_schema_version smallint NULL |
| screening_disposition | text | yes | screening_disposition text NULL |
| declared_coverage | jsonb | yes | declared_coverage jsonb NULL |
| declared_coverage_schema_version | smallint | yes | declared_coverage_schema_version smallint NULL |
| publication_digest | bytea | yes | publication_digest bytea NULL |

Constraints:
- `CONSTRAINT prc_analysis_runs_coverage_pair CHECK (((declared_coverage IS NULL) = (declared_coverage_schema_version IS NULL)))`
- `CONSTRAINT prc_analysis_runs_coverage_schema CHECK (((declared_coverage_schema_version IS NULL) OR (declared_coverage_schema_version = ANY (ARRAY[1, 2]))))`
- `CONSTRAINT prc_analysis_runs_coverage_source CHECK (((declared_coverage_schema_version IS DISTINCT FROM 2) OR ((NOT (jsonb_typeof((declared_coverage -> 'source'::text)) IS DISTINCT FROM 'object'::text)) AND ((NOT (jsonb_typeof((declared_coverage -> 'estimates'::text)) IS DISTINCT FROM 'null'::text)) OR (NOT (jsonb_typeof((declared_coverage -> 'estimates'::text)) IS DISTINCT FROM 'array'::text))))))`
- `CONSTRAINT prc_analysis_runs_document_version_id_fkey FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `CONSTRAINT prc_analysis_runs_expected_analysis_generation_check CHECK ((expected_analysis_generation >= 1))`
- `CONSTRAINT prc_analysis_runs_expected_document_head_generation_check CHECK ((expected_document_head_generation >= 1))`
- `CONSTRAINT prc_analysis_runs_generation_check CHECK ((generation >= 1))`
- `CONSTRAINT prc_analysis_runs_id_recipe_unique UNIQUE (id, recipe_hash)`
- `CONSTRAINT prc_analysis_runs_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_analysis_runs_publication_check CHECK ((publication_status = ANY (ARRAY['unpublished'::text, 'published'::text])))`
- `CONSTRAINT prc_analysis_runs_publication_digest_check CHECK (((publication_digest IS NULL) OR (octet_length(publication_digest) = 32)))`
- `CONSTRAINT prc_analysis_runs_publication_pair CHECK (((publication_status = 'published'::text) = (publication_digest IS NOT NULL)))`
- `CONSTRAINT prc_analysis_runs_recipe_hash_check CHECK ((octet_length(recipe_hash) = 32))`
- `CONSTRAINT prc_analysis_runs_recipe_schema_version_check CHECK ((recipe_schema_version > 0))`
- `CONSTRAINT prc_analysis_runs_representation_hash_check CHECK ((octet_length(representation_hash) = 32))`
- `CONSTRAINT prc_analysis_runs_representation_id_fkey FOREIGN KEY (representation_id) REFERENCES prc_document_representations(id)`
- `CONSTRAINT prc_analysis_runs_representation_identity_fk FOREIGN KEY (representation_id, document_version_id, representation_hash) REFERENCES prc_document_representations(id, document_version_id, content_hash)`
- `CONSTRAINT prc_analysis_runs_screening_disposition_check CHECK ((screening_disposition = ANY (ARRAY['extract'::text, 'retain'::text, 'review'::text, 'ignore'::text])))`
- `CONSTRAINT prc_analysis_runs_screening_disposition_pair CHECK (((screening_result IS NULL) = (screening_disposition IS NULL)))`
- `CONSTRAINT prc_analysis_runs_screening_schema_check CHECK (((screening_result_schema_version IS NULL) OR (screening_result_schema_version = 1)))`
- `CONSTRAINT prc_analysis_runs_screening_schema_pair CHECK (((screening_result IS NULL) = (screening_result_schema_version IS NULL)))`
- `CONSTRAINT prc_analysis_runs_supersedes_run_id_fkey FOREIGN KEY (supersedes_run_id) REFERENCES prc_analysis_runs(id)`
- `CONSTRAINT prc_analysis_runs_version_identity_unique UNIQUE (id, document_version_id)`

Indexes:
- `CREATE INDEX prc_analysis_runs_document_version_idx ON public.prc_analysis_runs USING btree (document_version_id)`

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
| logical_request_id | uuid | no | logical_request_id uuid NOT NULL |
| logical_key | bytea | no | logical_key bytea NOT NULL |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL |
| input_hash | bytea | no | input_hash bytea NOT NULL |
| purpose | text | no | purpose text NOT NULL |
| semantic_reask_ordinal | bigint | no | semantic_reask_ordinal bigint NOT NULL |
| replay_intent_id | uuid | no | replay_intent_id uuid NOT NULL |
| replay_of | uuid | yes | replay_of uuid → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| command_id | uuid | yes | command_id uuid |
| replay_actor | text | yes | replay_actor text |
| replay_reason | text | yes | replay_reason text |
| state | text | no | state text NOT NULL |
| request_epoch | bigint | no | request_epoch bigint NOT NULL |
| idempotency_key | text | yes | idempotency_key text |
| consumer_correlation_id | text | no | consumer_correlation_id text NOT NULL |
| reservation_amount | numeric(24,8) | no | reservation_amount numeric(24,8) DEFAULT 0 NOT NULL |
| context_json | jsonb | no | context_json jsonb NOT NULL |
| context_schema_version | smallint | no | context_schema_version smallint NOT NULL |
| max_physical_attempts | bigint | no | max_physical_attempts bigint NOT NULL |

Constraints:
- `CONSTRAINT llm_provider_requests_check CHECK (((replay_of IS NULL) = (command_id IS NULL)))`
- `CONSTRAINT llm_provider_requests_check1 CHECK (((command_id IS NULL) = (replay_actor IS NULL)))`
- `CONSTRAINT llm_provider_requests_check2 CHECK (((command_id IS NULL) = (replay_reason IS NULL)))`
- `CONSTRAINT llm_provider_requests_command_id_key UNIQUE (command_id)`
- `CONSTRAINT llm_provider_requests_context_schema_version_check CHECK ((context_schema_version = 1))`
- `CONSTRAINT llm_provider_requests_input_hash_check CHECK ((octet_length(input_hash) = 32))`
- `CONSTRAINT llm_provider_requests_logical_key_check CHECK ((octet_length(logical_key) = 32))`
- `CONSTRAINT llm_provider_requests_logical_key_key UNIQUE (logical_key)`
- `CONSTRAINT llm_provider_requests_max_physical_attempts_check CHECK (((max_physical_attempts >= 1) AND (max_physical_attempts <= 10)))`
- `CONSTRAINT llm_provider_requests_pkey PRIMARY KEY (logical_request_id)`
- `CONSTRAINT llm_provider_requests_purpose_check CHECK ((purpose = ANY (ARRAY['generate'::text, 'cache_create'::text, 'cache_refresh'::text, 'cache_storage'::text, 'file_upload'::text, 'lookup'::text])))`
- `CONSTRAINT llm_provider_requests_recipe_hash_check CHECK ((octet_length(recipe_hash) = 32))`
- `CONSTRAINT llm_provider_requests_replay_of_fkey FOREIGN KEY (replay_of) REFERENCES llm_provider_requests(logical_request_id)`
- `CONSTRAINT llm_provider_requests_request_epoch_check CHECK ((request_epoch >= 1))`
- `CONSTRAINT llm_provider_requests_reservation_amount_check CHECK ((reservation_amount >= (0)::numeric))`
- `CONSTRAINT llm_provider_requests_semantic_reask_ordinal_check CHECK (((semantic_reask_ordinal >= 0) AND (semantic_reask_ordinal <= 1)))`
- `CONSTRAINT llm_provider_requests_state_check CHECK ((state = ANY (ARRAY['prepared'::text, 'sending'::text, 'completed'::text, 'failed_known'::text, 'unknown'::text, 'abandoned_unknown'::text])))`

Indexes:
- `CREATE UNIQUE INDEX llm_provider_requests_command_id_key ON public.llm_provider_requests USING btree (command_id)`
- `CREATE UNIQUE INDEX llm_provider_requests_logical_key_key ON public.llm_provider_requests USING btree (logical_key)`
- `CREATE UNIQUE INDEX llm_provider_requests_pkey ON public.llm_provider_requests USING btree (logical_request_id)`
- `CREATE INDEX llm_provider_requests_unknown_state_idx ON public.llm_provider_requests USING btree (logical_request_id) WHERE (state = ANY (ARRAY['unknown'::text, 'abandoned_unknown'::text]))`

#### llm_budget_periods

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid NOT NULL |
| account_id | text | no | account_id text NOT NULL |
| currency | text | no | currency text NOT NULL |
| period_start | timestamptz | no | period_start timestamptz NOT NULL |
| period_end | timestamptz | no | period_end timestamptz NOT NULL |
| cap | numeric(24,8) | no | cap numeric(24,8) NOT NULL |
| spent | numeric(24,8) | no | spent numeric(24,8) DEFAULT 0 NOT NULL |
| reserved | numeric(24,8) | no | reserved numeric(24,8) DEFAULT 0 NOT NULL |

Constraints:
- `CONSTRAINT llm_budget_periods_account_id_currency_fkey FOREIGN KEY (account_id, currency) REFERENCES llm_budget_accounts(account_id, currency)`
- `CONSTRAINT llm_budget_periods_account_id_currency_period_start_period__key UNIQUE (account_id, currency, period_start, period_end)`
- `CONSTRAINT llm_budget_periods_cap_check CHECK ((cap >= (0)::numeric))`
- `CONSTRAINT llm_budget_periods_check CHECK ((period_end > period_start))`
- `CONSTRAINT llm_budget_periods_check1 CHECK (((spent + reserved) <= cap))`
- `CONSTRAINT llm_budget_periods_pkey PRIMARY KEY (id)`
- `CONSTRAINT llm_budget_periods_reserved_check CHECK ((reserved >= (0)::numeric))`
- `CONSTRAINT llm_budget_periods_spent_check CHECK ((spent >= (0)::numeric))`

Indexes:
- `CREATE UNIQUE INDEX llm_budget_periods_account_id_currency_period_start_period__key ON public.llm_budget_periods USING btree (account_id, currency, period_start, period_end)`
- `CREATE UNIQUE INDEX llm_budget_periods_pkey ON public.llm_budget_periods USING btree (id)`

#### llm_budget_reservations

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid NOT NULL |
| operation_id | uuid | no | operation_id uuid NOT NULL → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.9 |
| period_id | uuid | no | period_id uuid NOT NULL → llm_budget_periods(id) ON DELETE NO ACTION @P3.9 |
| upper_bound | numeric(24,8) | no | upper_bound numeric(24,8) NOT NULL |
| state | text | no | state text NOT NULL |
| settled_amount | numeric(24,8) | yes | settled_amount numeric(24,8) |
| pricing_snapshot | jsonb | no | pricing_snapshot jsonb NOT NULL |
| pricing_snapshot_schema_version | smallint | no | pricing_snapshot_schema_version smallint NOT NULL |

Constraints:
- `CONSTRAINT llm_budget_reservations_check CHECK (((state = 'settled'::text) = (settled_amount IS NOT NULL)))`
- `CONSTRAINT llm_budget_reservations_check1 CHECK (((settled_amount >= (0)::numeric) AND (settled_amount <= upper_bound)))`
- `CONSTRAINT llm_budget_reservations_id_operation_id_key UNIQUE (id, operation_id)`
- `CONSTRAINT llm_budget_reservations_operation_id_fkey FOREIGN KEY (operation_id) REFERENCES llm_provider_calls(physical_call_id)`
- `CONSTRAINT llm_budget_reservations_operation_id_key UNIQUE (operation_id)`
- `CONSTRAINT llm_budget_reservations_period_id_fkey FOREIGN KEY (period_id) REFERENCES llm_budget_periods(id)`
- `CONSTRAINT llm_budget_reservations_pkey PRIMARY KEY (id)`
- `CONSTRAINT llm_budget_reservations_pricing_snapshot_schema_version_check CHECK ((pricing_snapshot_schema_version = 1))`
- `CONSTRAINT llm_budget_reservations_state_check CHECK ((state = ANY (ARRAY['reserved'::text, 'settled'::text])))`
- `CONSTRAINT llm_budget_reservations_upper_bound_check CHECK ((upper_bound >= (0)::numeric))`

Indexes:
- `CREATE UNIQUE INDEX llm_budget_reservations_id_operation_id_key ON public.llm_budget_reservations USING btree (id, operation_id)`
- `CREATE UNIQUE INDEX llm_budget_reservations_operation_id_key ON public.llm_budget_reservations USING btree (operation_id)`
- `CREATE INDEX llm_budget_reservations_period_state_idx ON public.llm_budget_reservations USING btree (period_id, state)`
- `CREATE UNIQUE INDEX llm_budget_reservations_pkey ON public.llm_budget_reservations USING btree (id)`

#### llm_provider_calls

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| physical_call_id | uuid | no | physical_call_id uuid NOT NULL |
| logical_request_id | uuid | no | logical_request_id uuid NOT NULL → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| ordinal | bigint | no | ordinal bigint NOT NULL |
| provider_id | bigint | no | provider_id bigint NOT NULL → llm_providers(id) ON DELETE NO ACTION @P3.9 |
| account_id | text | no | account_id text NOT NULL |
| api_surface | text | no | api_surface text NOT NULL |
| model | text | no | model text NOT NULL |
| resolved_model_version | text | yes | resolved_model_version text |
| request_epoch | bigint | no | request_epoch bigint NOT NULL |
| owner_attempt_id | uuid | no | owner_attempt_id uuid NOT NULL |
| owner_work_generation | bigint | no | owner_work_generation bigint NOT NULL |
| send_authorization_token | uuid | no | send_authorization_token uuid NOT NULL |
| send_claimed_at | timestamptz | yes | send_claimed_at timestamptz |
| sent_at | timestamptz | yes | sent_at timestamptz |
| response_at | timestamptz | yes | response_at timestamptz |
| wire_fingerprint | bytea | yes | wire_fingerprint bytea CHECK(octet_length(wire_fingerprint)=32) |
| http_status | smallint | yes | http_status smallint |
| finish_reason | text | yes | finish_reason text |
| usage_status | text | no | usage_status text NOT NULL |
| usage | jsonb | yes | usage jsonb |
| usage_schema_version | smallint | yes | usage_schema_version smallint |
| cost_estimate | numeric(24,8) | no | cost_estimate numeric(24,8) NOT NULL |
| cost_actual | numeric(24,8) | yes | cost_actual numeric(24,8) |
| receipt_id | uuid | yes | receipt_id uuid |
| receipt_state | text | no | receipt_state text NOT NULL |
| receipt_upload_key | text | no | receipt_upload_key text NOT NULL |
| receipt_disk | text | yes | receipt_disk text |
| receipt_key | text | yes | receipt_key text |
| receipt_hash | bytea | yes | receipt_hash bytea |
| receipt_schema_version | smallint | yes | receipt_schema_version smallint |
| reservation_id | uuid | no | reservation_id uuid NOT NULL → llm_budget_reservations(id) ON DELETE NO ACTION @P3.9 |
| outcome | text | yes | outcome text |
| charge_evidence | text | yes | charge_evidence text |

Constraints:
- `CONSTRAINT llm_provider_calls_check CHECK (((usage IS NULL) = (usage_schema_version IS NULL)))`
- `CONSTRAINT llm_provider_calls_check1 CHECK (((receipt_key IS NULL) = (receipt_hash IS NULL)))`
- `CONSTRAINT llm_provider_calls_check2 CHECK (((receipt_key IS NULL) = (receipt_schema_version IS NULL)))`
- `CONSTRAINT llm_provider_calls_check3 CHECK (((receipt_state = 'verified'::text) = (receipt_key IS NOT NULL)))`
- `CONSTRAINT llm_provider_calls_check4 CHECK (((receipt_state <> ALL (ARRAY['verified'::text, 'unknown'::text])) OR (send_claimed_at IS NOT NULL)))`
- `CONSTRAINT llm_provider_calls_check5 CHECK (((receipt_state <> 'not_sent'::text) OR ((send_claimed_at IS NULL) AND (cost_actual = (0)::numeric))))`
- `CONSTRAINT llm_provider_calls_check6 CHECK (((receipt_key IS NULL) OR (receipt_key = receipt_upload_key)))`
- `CONSTRAINT llm_provider_calls_cost_actual_check CHECK ((cost_actual >= (0)::numeric))`
- `CONSTRAINT llm_provider_calls_cost_estimate_check CHECK ((cost_estimate >= (0)::numeric))`
- `CONSTRAINT llm_provider_calls_http_status_check CHECK (((http_status >= 100) AND (http_status <= 599)))`
- `CONSTRAINT llm_provider_calls_logical_request_id_fkey FOREIGN KEY (logical_request_id) REFERENCES llm_provider_requests(logical_request_id)`
- `CONSTRAINT llm_provider_calls_logical_request_id_ordinal_key UNIQUE (logical_request_id, ordinal)`
- `CONSTRAINT llm_provider_calls_ordinal_check CHECK ((ordinal >= 1))`
- `CONSTRAINT llm_provider_calls_outcome_check CHECK ((outcome = ANY (ARRAY['not_sent'::text, 'success'::text, 'known_nonbillable_cache_miss'::text, 'known_retryable_nonbillable'::text, 'billed_error'::text, 'unknown'::text])))`
- `CONSTRAINT llm_provider_calls_owner_work_generation_check CHECK ((owner_work_generation >= 1))`
- `CONSTRAINT llm_provider_calls_physical_call_id_logical_request_id_key UNIQUE (physical_call_id, logical_request_id)`
- `CONSTRAINT llm_provider_calls_pkey PRIMARY KEY (physical_call_id)`
- `CONSTRAINT llm_provider_calls_provider_id_fkey FOREIGN KEY (provider_id) REFERENCES llm_providers(id)`
- `CONSTRAINT llm_provider_calls_receipt_hash_check CHECK ((octet_length(receipt_hash) = 32))`
- `CONSTRAINT llm_provider_calls_receipt_id_key UNIQUE (receipt_id)`
- `CONSTRAINT llm_provider_calls_receipt_schema_version_check CHECK ((receipt_schema_version = 1))`
- `CONSTRAINT llm_provider_calls_receipt_state_check CHECK ((receipt_state = ANY (ARRAY['pending'::text, 'verified'::text, 'not_sent'::text, 'unknown'::text])))`
- `CONSTRAINT llm_provider_calls_receipt_upload_key_key UNIQUE (receipt_upload_key)`
- `CONSTRAINT llm_provider_calls_request_epoch_check CHECK ((request_epoch >= 1))`
- `CONSTRAINT llm_provider_calls_reservation_fk FOREIGN KEY (reservation_id, physical_call_id) REFERENCES llm_budget_reservations(id, operation_id) DEFERRABLE INITIALLY DEFERRED`
- `CONSTRAINT llm_provider_calls_reservation_id_key UNIQUE (reservation_id)`
- `CONSTRAINT llm_provider_calls_usage_schema_version_check CHECK ((usage_schema_version > 0))`
- `CONSTRAINT llm_provider_calls_usage_status_check CHECK ((usage_status = ANY (ARRAY['known'::text, 'unknown'::text])))`
- `CONSTRAINT llm_provider_calls_wire_fingerprint_check CHECK ((octet_length(wire_fingerprint) = 32))`

Indexes:
- `CREATE INDEX llm_provider_calls_account_idx ON public.llm_provider_calls USING btree (account_id, logical_request_id)`
- `CREATE UNIQUE INDEX llm_provider_calls_logical_request_id_ordinal_key ON public.llm_provider_calls USING btree (logical_request_id, ordinal)`
- `CREATE UNIQUE INDEX llm_provider_calls_physical_call_id_logical_request_id_key ON public.llm_provider_calls USING btree (physical_call_id, logical_request_id)`
- `CREATE UNIQUE INDEX llm_provider_calls_pkey ON public.llm_provider_calls USING btree (physical_call_id)`
- `CREATE UNIQUE INDEX llm_provider_calls_receipt_id_key ON public.llm_provider_calls USING btree (receipt_id)`
- `CREATE UNIQUE INDEX llm_provider_calls_receipt_upload_key_key ON public.llm_provider_calls USING btree (receipt_upload_key)`
- `CREATE UNIQUE INDEX llm_provider_calls_reservation_id_key ON public.llm_provider_calls USING btree (reservation_id)`
- `CREATE INDEX llm_provider_calls_send_time_idx ON public.llm_provider_calls USING btree (COALESCE(sent_at, send_claimed_at))`

#### prc_analysis_llm_requests

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s01,s04,s05,s06,s08,s09,s10.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid NOT NULL |
| analysis_run_id | uuid | no | analysis_run_id uuid NOT NULL → prc_analysis_runs(id) ON DELETE NO ACTION @P3.9 |
| work_id | uuid | no | work_id uuid NOT NULL → prc_process_work(id) ON DELETE NO ACTION @P3.9 |
| attempt_id | uuid | no | attempt_id uuid NOT NULL → prc_process_attempts(id) ON DELETE NO ACTION @P3.9 |
| logical_request_id | uuid | no | logical_request_id uuid NOT NULL → llm_provider_requests(logical_request_id) ON DELETE NO ACTION @P3.9 |
| physical_call_id | uuid | no | physical_call_id uuid NOT NULL → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.9 |
| expected_input_hash | bytea | no | expected_input_hash bytea NOT NULL |
| recipe_hash | bytea | no | recipe_hash bytea NOT NULL |
| recovery_active | boolean | no | recovery_active boolean DEFAULT true NOT NULL |

Constraints:
- `CONSTRAINT prc_analysis_llm_requests_analysis_run_id_fkey FOREIGN KEY (analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `CONSTRAINT prc_analysis_llm_requests_analysis_run_id_recipe_hash_fkey FOREIGN KEY (analysis_run_id, recipe_hash) REFERENCES prc_analysis_runs(id, recipe_hash)`
- `CONSTRAINT prc_analysis_llm_requests_attempt_id_fkey FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `CONSTRAINT prc_analysis_llm_requests_expected_input_hash_check CHECK ((octet_length(expected_input_hash) = 32))`
- `CONSTRAINT prc_analysis_llm_requests_logical_request_id_fkey FOREIGN KEY (logical_request_id) REFERENCES llm_provider_requests(logical_request_id)`
- `CONSTRAINT prc_analysis_llm_requests_physical_call_id_fkey FOREIGN KEY (physical_call_id) REFERENCES llm_provider_calls(physical_call_id)`
- `CONSTRAINT prc_analysis_llm_requests_physical_call_id_key UNIQUE (physical_call_id)`
- `CONSTRAINT prc_analysis_llm_requests_physical_call_id_logical_request_fkey FOREIGN KEY (physical_call_id, logical_request_id) REFERENCES llm_provider_calls(physical_call_id, logical_request_id)`
- `CONSTRAINT prc_analysis_llm_requests_pkey PRIMARY KEY (id)`
- `CONSTRAINT prc_analysis_llm_requests_recipe_hash_check CHECK ((octet_length(recipe_hash) = 32))`
- `CONSTRAINT prc_analysis_llm_requests_work_id_fkey FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `CREATE UNIQUE INDEX prc_analysis_llm_active_work ON public.prc_analysis_llm_requests USING btree (work_id) WHERE recovery_active`
- `CREATE UNIQUE INDEX prc_analysis_llm_requests_physical_call_id_key ON public.prc_analysis_llm_requests USING btree (physical_call_id)`
- `CREATE UNIQUE INDEX prc_analysis_llm_requests_pkey ON public.prc_analysis_llm_requests USING btree (id)`
- `CREATE INDEX prc_analysis_llm_work_idx ON public.prc_analysis_llm_requests USING btree (work_id, attempt_id)`
- `CREATE INDEX prc_analysis_llm_requests_run_call_idx ON public.prc_analysis_llm_requests USING btree (analysis_run_id, physical_call_id)`

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
| creation_token | uuid | yes | creation_token uuid NULL REFERENCES llm_provider_calls(physical_call_id) → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.12 |
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
| creation_token | uuid | yes | creation_token uuid NULL REFERENCES llm_provider_calls(physical_call_id) → llm_provider_calls(physical_call_id) ON DELETE NO ACTION @P3.12 |
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

#### llm_budget_accounts

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s08,s09.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| account_id | text | no | account_id text NOT NULL |
| currency | text | no | currency text NOT NULL |
| unknown_count_threshold | bigint | no | unknown_count_threshold bigint DEFAULT 1 NOT NULL |
| hard_exposure_cap | numeric(24,8) | yes | hard_exposure_cap numeric(24,8) |
| policy_version | bigint | no | policy_version bigint DEFAULT 1 NOT NULL |
| policy_actor | text | no | policy_actor text NOT NULL |
| policy_reason | text | no | policy_reason text NOT NULL |

Constraints:
- `CONSTRAINT llm_budget_accounts_account_id_key UNIQUE (account_id)`
- `CONSTRAINT llm_budget_accounts_currency_check CHECK ((currency ~ '^[A-Z]{3}$'::text))`
- `CONSTRAINT llm_budget_accounts_hard_exposure_cap_check CHECK ((hard_exposure_cap >= (0)::numeric))`
- `CONSTRAINT llm_budget_accounts_pkey PRIMARY KEY (account_id, currency)`
- `CONSTRAINT llm_budget_accounts_policy_version_check CHECK ((policy_version >= 1))`
- `CONSTRAINT llm_budget_accounts_unknown_count_threshold_check CHECK ((unknown_count_threshold >= 1))`

Indexes:
- `CREATE UNIQUE INDEX llm_budget_accounts_account_id_key ON public.llm_budget_accounts USING btree (account_id)`
- `CREATE UNIQUE INDEX llm_budget_accounts_pkey ON public.llm_budget_accounts USING btree (account_id, currency)`

#### llm_financial_actions

Create: P3.9; activation boundary: P3.13; owner: `Services/Llm/Execution/RequestLedger`; retention: financial. Contract: documents-ai#s08,s09.

| Column | Type | Nullable | Declaration / FK installed |
| --- | --- | --- | --- |
| id | uuid | no | id uuid NOT NULL |
| account_id | text | no | account_id text NOT NULL |
| currency | text | no | currency text NOT NULL |
| physical_call_id | uuid | yes | physical_call_id uuid |
| action | text | no | action text NOT NULL |
| actor | text | no | actor text NOT NULL |
| reason | text | no | reason text NOT NULL |
| details | jsonb | no | details jsonb NOT NULL |
| details_schema_version | smallint | no | details_schema_version smallint NOT NULL |
| occurred_at | timestamptz | no | occurred_at timestamptz DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `CONSTRAINT llm_financial_actions_account_id_currency_fkey FOREIGN KEY (account_id, currency) REFERENCES llm_budget_accounts(account_id, currency)`
- `CONSTRAINT llm_financial_actions_action_check CHECK ((action = ANY (ARRAY['policy_change'::text, 'conservative_settlement'::text, 'abandon_unknown'::text, 'receipt_reconciliation'::text])))`
- `CONSTRAINT llm_financial_actions_actor_check CHECK ((length(actor) > 0))`
- `CONSTRAINT llm_financial_actions_details_schema_version_check CHECK ((details_schema_version = 1))`
- `CONSTRAINT llm_financial_actions_physical_call_id_fkey FOREIGN KEY (physical_call_id) REFERENCES llm_provider_calls(physical_call_id)`
- `CONSTRAINT llm_financial_actions_pkey PRIMARY KEY (id)`
- `CONSTRAINT llm_financial_actions_reason_check CHECK ((length(reason) > 0))`

Indexes:
- `CREATE UNIQUE INDEX llm_financial_actions_pkey ON public.llm_financial_actions USING btree (id)`

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

#### prc_documents @P2.18

Canonical references use platform/source key; CHECK requires the full descriptor/version/observation tuple. Nullable only for surviving manual/legacy document rows until P3.5. Observed title/external_id use existing columns. Access rotation increments descriptor version, not content head.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| source_document_key | text | yes | source_document_key text COLLATE "C" NULL |
| source_key_version | integer | yes | source_key_version integer NULL |
| access_descriptor | jsonb | yes | access_descriptor jsonb NULL |
| access_schema_version | integer | yes | access_schema_version integer NULL |
| access_descriptor_version | bigint | yes | access_descriptor_version bigint NULL |
| reference_observed_at | timestamptz | yes | reference_observed_at timestamptz NULL |

Remove at this cutover: none.

Constraints:
- `CHECK ((((source_document_key IS NULL) AND (source_key_version IS NULL) AND (access_descriptor IS NULL) AND (access_schema_version IS NULL) AND (access_descriptor_version IS NULL) AND (reference_observed_at IS NULL)) OR ((source_document_key IS NOT NULL) AND ((octet_length(source_document_key) >= 1) AND (octet_length(source_document_key) <= 1024)) AND (source_key_version IS NOT NULL) AND (source_key_version >= 1) AND (access_descriptor IS NOT NULL) AND (jsonb_typeof(access_descriptor) = 'object'::text) AND (access_schema_version IS NOT NULL) AND (access_schema_version = 1) AND (access_descriptor_version IS NOT NULL) AND (access_descriptor_version >= 1) AND (reference_observed_at IS NOT NULL))))`

#### prc_crawlers @P2.6

Atomic network binding cutover; acquisition execution remains on existing owners until P2.18.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| route_profile_id | uuid | yes | route_profile_id uuid NULL REFERENCES prc_network_route_profiles(id) |

Remove at this cutover: proxy_mode, proxy_country.

Constraints:

#### prc_crawlers @P2.18

Profile/version/listing kind select typed capabilities through registered PlatformDefinition; prepared immutable configuration pins filter, route policy and adapter versions. No parallel mutable capability JSON.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| listing_kind | text | no | listing_kind text NOT NULL CHECK(listing_kind IN ('fresh','archive','delta')) |
| profile_version | bigint | no | profile_version bigint NOT NULL DEFAULT 1 |

Remove at this cutover: mode, source_method, fallback_method, execution_lane, stop_policy, options.

Constraints:

#### prc_proxies @P2.6

Sole health/control owner становится Network, все transport callers и reactivate переключены P2.6.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| route_id | uuid | no | route_id uuid NOT NULL REFERENCES prc_network_routes(id) |

Remove at this cutover: open_until, open_count, last_failure_reason, last_success_at, last_failure_at, fails_count, is_active, disabled_at, disabled_reason, password.

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

A→B→A = три revisions и два blobs. Existing bigint PK/document/parent FK сохраняются; lineage и source validators не execution authority. Coordinator-authorized additive staging: legacy rows retain NULL canonical tuple and version content_hash hex text until P3.5. Canonical tuple CHECK + immutable/reference guards are strict now. P3.5 MUST remove legacy predicates, require canonical tuple/explicit disk on ALL rows, convert versions.content_hash to bytea and remove legacy unique indexes with writer cutover.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| revision_no | bigint | yes | revision_no bigint NULL CHECK(revision_no>=1) |
| blob_id | uuid | yes | blob_id uuid NULL REFERENCES prc_document_blobs(id) |
| content_hash | text | no | content_hash text NOT NULL |
| etag | text | yes | etag text NULL |
| last_modified | timestamptz | yes | last_modified timestamptz NULL |
| first_seen_at | timestamptz | yes | first_seen_at timestamptz NULL |
| normalized_entry_path | text | yes | normalized_entry_path text NULL |
| child_ordinal | bigint | yes | child_ordinal bigint NULL CHECK(child_ordinal>=0) |

Remove at this cutover: none.

Constraints:
- `UNIQUE(document_id,revision_no)`
- `UNIQUE(parent_version_id,normalized_entry_path)`
- `DROP UNIQUE(document_id,content_hash)`

#### prc_document_representations @P3.1

Existing bigint PK; explicit immutable disk/key/hash/range and complete source page coverage. Coordinator-authorized additive staging: legacy rows retain NULL canonical tuple and version content_hash hex text until P3.5. Canonical tuple CHECK + immutable/reference guards are strict now. P3.5 MUST remove legacy predicates, require canonical tuple/explicit disk on ALL rows, convert versions.content_hash to bytea and remove legacy unique indexes with writer cutover.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| disk | text | yes | disk text NULL |
| preparation_recipe_hash | bytea | yes | preparation_recipe_hash bytea NULL CHECK(octet_length(preparation_recipe_hash)=32) |
| content_hash | bytea | yes | content_hash bytea NULL CHECK(octet_length(content_hash)=32) |
| range_key | text | yes | range_key text NULL |
| tool_version | text | yes | tool_version text NULL |
| coverage_manifest | jsonb | yes | coverage_manifest jsonb NULL |
| coverage_manifest_schema_version | smallint | yes | coverage_manifest_schema_version smallint NULL CHECK(coverage_manifest_schema_version>0) |
| blob_id | uuid | yes | blob_id uuid NULL REFERENCES prc_document_blobs(id) |

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

#### prc_raw_artifacts @P3.1

Двухфазная retention, pending/pinned/ref gate; object I/O вне tx. Отложено к owning document/storage cutover P3.1: закрытый P1.14 не менял raw artifacts, P1.15 ограничен projection retention.

| Column | Type | Nullable | Target declaration |
| --- | --- | --- | --- |
| deletion_generation | bigint | no | deletion_generation bigint NOT NULL DEFAULT 0 CHECK(deletion_generation>=0) |
| deletion_marked_at | timestamptz | yes | deletion_marked_at timestamptz NULL |

Remove at this cutover: none.

Constraints:

### Installed baseline (before Process ledger)

#### azg_panel_state

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| panel | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| version | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |
| incarnation | character varying / varchar | NO | character varying(26) NOT NULL COLLATE pg_catalog."C" |
| updated_at | timestamp without time zone / timestamp | NO | timestamp(0) without time zone NOT NULL |
| epoch | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |

Constraints:
- `azg_panel_state_pkey`: `PRIMARY KEY (panel)`

Indexes:
- `azg_panel_state_pkey`: `CREATE UNIQUE INDEX azg_panel_state_pkey ON public.azg_panel_state USING btree (panel)`

#### azg_permission_grants

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| panel | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| tenant_key | character varying / varchar | NO | character varying(200) NOT NULL COLLATE pg_catalog."C" |
| tenant_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| tenant_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| permission | character varying / varchar | NO | character varying(255) NOT NULL COLLATE pg_catalog."C" |
| subject_type | character varying / varchar | NO | character varying(128) NOT NULL COLLATE pg_catalog."C" |
| subject_id | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| context_key | character varying / varchar | NO | character varying(200) NOT NULL COLLATE pg_catalog."C" |
| context_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| context_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| origin | character varying / varchar | NO | character varying(128) DEFAULT 'manual'::character varying NOT NULL COLLATE pg_catalog."C" |
| expires_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| actor_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| actor_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| actor_reason | text / text | YES | text |
| meta | json / json | YES | json |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `azg_permission_grants_pkey`: `PRIMARY KEY (id)`
- `azg_pg_context_pair`: `CHECK (((((context_key)::text = 'global'::text) = ((context_type IS NULL) AND (context_id IS NULL))) AND ((context_type IS NULL) = (context_id IS NULL))))`
- `azg_pg_identity`: `UNIQUE (panel, tenant_key, permission, subject_type, subject_id, context_key, origin)`
- `azg_pg_tenant_pair`: `CHECK (((((tenant_key)::text = 'global'::text) = ((tenant_type IS NULL) AND (tenant_id IS NULL))) AND ((tenant_type IS NULL) = (tenant_id IS NULL))))`

Indexes:
- `azg_permission_grants_pkey`: `CREATE UNIQUE INDEX azg_permission_grants_pkey ON public.azg_permission_grants USING btree (id)`
- `azg_pg_context`: `CREATE INDEX azg_pg_context ON public.azg_permission_grants USING btree (panel, tenant_key, context_type, context_id)`
- `azg_pg_expiry`: `CREATE INDEX azg_pg_expiry ON public.azg_permission_grants USING btree (expires_at)`
- `azg_pg_identity`: `CREATE UNIQUE INDEX azg_pg_identity ON public.azg_permission_grants USING btree (panel, tenant_key, permission, subject_type, subject_id, context_key, origin)`
- `azg_pg_origin`: `CREATE INDEX azg_pg_origin ON public.azg_permission_grants USING btree (panel, tenant_key, origin)`
- `azg_pg_subject`: `CREATE INDEX azg_pg_subject ON public.azg_permission_grants USING btree (panel, tenant_key, subject_type, subject_id, context_key)`

#### azg_permissions

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| panel | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| tenant_key | character varying / varchar | NO | character varying(200) NOT NULL COLLATE pg_catalog."C" |
| tenant_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| tenant_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| name | character varying / varchar | NO | character varying(255) NOT NULL COLLATE pg_catalog."C" |
| label | character varying / varchar | YES | character varying(191) |
| group | character varying / varchar | YES | character varying(191) |
| description | text / text | YES | text |
| meta | json / json | YES | json |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `azg_permissions_pkey`: `PRIMARY KEY (id)`
- `azg_pm_identity`: `UNIQUE (panel, tenant_key, name)`
- `azg_pm_tenant_pair`: `CHECK (((((tenant_key)::text = 'global'::text) = ((tenant_type IS NULL) AND (tenant_id IS NULL))) AND ((tenant_type IS NULL) = (tenant_id IS NULL))))`

Indexes:
- `azg_permissions_pkey`: `CREATE UNIQUE INDEX azg_permissions_pkey ON public.azg_permissions USING btree (id)`
- `azg_pm_identity`: `CREATE UNIQUE INDEX azg_pm_identity ON public.azg_permissions USING btree (panel, tenant_key, name)`

#### azg_role_grants

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| panel | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| tenant_key | character varying / varchar | NO | character varying(200) NOT NULL COLLATE pg_catalog."C" |
| tenant_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| tenant_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| role | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| subject_type | character varying / varchar | NO | character varying(128) NOT NULL COLLATE pg_catalog."C" |
| subject_id | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| context_key | character varying / varchar | NO | character varying(200) NOT NULL COLLATE pg_catalog."C" |
| context_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| context_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| origin | character varying / varchar | NO | character varying(128) DEFAULT 'manual'::character varying NOT NULL COLLATE pg_catalog."C" |
| expires_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| actor_type | character varying / varchar | YES | character varying(128) COLLATE pg_catalog."C" |
| actor_id | character varying / varchar | YES | character varying(64) COLLATE pg_catalog."C" |
| actor_reason | text / text | YES | text |
| meta | json / json | YES | json |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `azg_rg_context_pair`: `CHECK (((((context_key)::text = 'global'::text) = ((context_type IS NULL) AND (context_id IS NULL))) AND ((context_type IS NULL) = (context_id IS NULL))))`
- `azg_rg_identity`: `UNIQUE (panel, tenant_key, role, subject_type, subject_id, context_key, origin)`
- `azg_rg_tenant_pair`: `CHECK (((((tenant_key)::text = 'global'::text) = ((tenant_type IS NULL) AND (tenant_id IS NULL))) AND ((tenant_type IS NULL) = (tenant_id IS NULL))))`
- `azg_role_grants_pkey`: `PRIMARY KEY (id)`

Indexes:
- `azg_rg_context`: `CREATE INDEX azg_rg_context ON public.azg_role_grants USING btree (panel, tenant_key, context_type, context_id)`
- `azg_rg_expiry`: `CREATE INDEX azg_rg_expiry ON public.azg_role_grants USING btree (expires_at)`
- `azg_rg_identity`: `CREATE UNIQUE INDEX azg_rg_identity ON public.azg_role_grants USING btree (panel, tenant_key, role, subject_type, subject_id, context_key, origin)`
- `azg_rg_origin`: `CREATE INDEX azg_rg_origin ON public.azg_role_grants USING btree (panel, tenant_key, origin)`
- `azg_rg_subject`: `CREATE INDEX azg_rg_subject ON public.azg_role_grants USING btree (panel, tenant_key, subject_type, subject_id, context_key)`
- `azg_role_grants_pkey`: `CREATE UNIQUE INDEX azg_role_grants_pkey ON public.azg_role_grants USING btree (id)`

#### azg_storage_state

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | smallint / int2 | NO | smallint NOT NULL |
| schema | json / json | NO | json NOT NULL |

Constraints:
- `azg_ss_singleton`: `CHECK ((id = 1))`
- `azg_storage_state_pkey`: `PRIMARY KEY (id)`

Indexes:
- `azg_storage_state_pkey`: `CREATE UNIQUE INDEX azg_storage_state_pkey ON public.azg_storage_state USING btree (id)`

#### azg_subject_revisions

Owner: Framework/vendor; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| panel | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| subject_type | character varying / varchar | NO | character varying(128) NOT NULL COLLATE pg_catalog."C" |
| subject_id | character varying / varchar | NO | character varying(64) NOT NULL COLLATE pg_catalog."C" |
| revision | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |

Constraints:
- `azg_subject_revisions_pkey`: `PRIMARY KEY (panel, subject_type, subject_id)`

Indexes:
- `azg_subject_revisions_pkey`: `CREATE UNIQUE INDEX azg_subject_revisions_pkey ON public.azg_subject_revisions USING btree (panel, subject_type, subject_id)`

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

#### llm_budget_accounts

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| account_id | text / text | NO | text NOT NULL |
| currency | text / text | NO | text NOT NULL |
| unknown_count_threshold | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| hard_exposure_cap | numeric / numeric | YES | numeric(24,8) |
| policy_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| policy_actor | text / text | NO | text NOT NULL |
| policy_reason | text / text | NO | text NOT NULL |

Constraints:
- `llm_budget_accounts_account_id_key`: `UNIQUE (account_id)`
- `llm_budget_accounts_currency_check`: `CHECK ((currency ~ '^[A-Z]{3}$'::text))`
- `llm_budget_accounts_hard_exposure_cap_check`: `CHECK ((hard_exposure_cap >= (0)::numeric))`
- `llm_budget_accounts_pkey`: `PRIMARY KEY (account_id, currency)`
- `llm_budget_accounts_policy_version_check`: `CHECK ((policy_version >= 1))`
- `llm_budget_accounts_unknown_count_threshold_check`: `CHECK ((unknown_count_threshold >= 1))`

Indexes:
- `llm_budget_accounts_account_id_key`: `CREATE UNIQUE INDEX llm_budget_accounts_account_id_key ON public.llm_budget_accounts USING btree (account_id)`
- `llm_budget_accounts_pkey`: `CREATE UNIQUE INDEX llm_budget_accounts_pkey ON public.llm_budget_accounts USING btree (account_id, currency)`

#### llm_budget_periods

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| account_id | text / text | NO | text NOT NULL |
| currency | text / text | NO | text NOT NULL |
| period_start | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| period_end | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| cap | numeric / numeric | NO | numeric(24,8) NOT NULL |
| spent | numeric / numeric | NO | numeric(24,8) DEFAULT 0 NOT NULL |
| reserved | numeric / numeric | NO | numeric(24,8) DEFAULT 0 NOT NULL |

Constraints:
- `llm_budget_periods_account_id_currency_fkey`: `FOREIGN KEY (account_id, currency) REFERENCES llm_budget_accounts(account_id, currency)`
- `llm_budget_periods_account_id_currency_period_start_period__key`: `UNIQUE (account_id, currency, period_start, period_end)`
- `llm_budget_periods_cap_check`: `CHECK ((cap >= (0)::numeric))`
- `llm_budget_periods_check`: `CHECK ((period_end > period_start))`
- `llm_budget_periods_check1`: `CHECK (((spent + reserved) <= cap))`
- `llm_budget_periods_pkey`: `PRIMARY KEY (id)`
- `llm_budget_periods_reserved_check`: `CHECK ((reserved >= (0)::numeric))`
- `llm_budget_periods_spent_check`: `CHECK ((spent >= (0)::numeric))`

Indexes:
- `llm_budget_periods_account_id_currency_period_start_period__key`: `CREATE UNIQUE INDEX llm_budget_periods_account_id_currency_period_start_period__key ON public.llm_budget_periods USING btree (account_id, currency, period_start, period_end)`
- `llm_budget_periods_pkey`: `CREATE UNIQUE INDEX llm_budget_periods_pkey ON public.llm_budget_periods USING btree (id)`

#### llm_budget_reservations

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| period_id | uuid / uuid | NO | uuid NOT NULL |
| upper_bound | numeric / numeric | NO | numeric(24,8) NOT NULL |
| state | text / text | NO | text NOT NULL |
| settled_amount | numeric / numeric | YES | numeric(24,8) |
| pricing_snapshot | jsonb / jsonb | NO | jsonb NOT NULL |
| pricing_snapshot_schema_version | smallint / int2 | NO | smallint NOT NULL |

Constraints:
- `llm_budget_reservations_check`: `CHECK (((state = 'settled'::text) = (settled_amount IS NOT NULL)))`
- `llm_budget_reservations_check1`: `CHECK (((settled_amount >= (0)::numeric) AND (settled_amount <= upper_bound)))`
- `llm_budget_reservations_id_operation_id_key`: `UNIQUE (id, operation_id)`
- `llm_budget_reservations_operation_id_fkey`: `FOREIGN KEY (operation_id) REFERENCES llm_provider_calls(physical_call_id)`
- `llm_budget_reservations_operation_id_key`: `UNIQUE (operation_id)`
- `llm_budget_reservations_period_id_fkey`: `FOREIGN KEY (period_id) REFERENCES llm_budget_periods(id)`
- `llm_budget_reservations_pkey`: `PRIMARY KEY (id)`
- `llm_budget_reservations_pricing_snapshot_schema_version_check`: `CHECK ((pricing_snapshot_schema_version = 1))`
- `llm_budget_reservations_state_check`: `CHECK ((state = ANY (ARRAY['reserved'::text, 'settled'::text])))`
- `llm_budget_reservations_upper_bound_check`: `CHECK ((upper_bound >= (0)::numeric))`

Indexes:
- `llm_budget_reservations_id_operation_id_key`: `CREATE UNIQUE INDEX llm_budget_reservations_id_operation_id_key ON public.llm_budget_reservations USING btree (id, operation_id)`
- `llm_budget_reservations_operation_id_key`: `CREATE UNIQUE INDEX llm_budget_reservations_operation_id_key ON public.llm_budget_reservations USING btree (operation_id)`
- `llm_budget_reservations_period_state_idx`: `CREATE INDEX llm_budget_reservations_period_state_idx ON public.llm_budget_reservations USING btree (period_id, state)`
- `llm_budget_reservations_pkey`: `CREATE UNIQUE INDEX llm_budget_reservations_pkey ON public.llm_budget_reservations USING btree (id)`

#### llm_context_caches

Owner: Services/Llm/Cache; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| provider_id | bigint / int8 | NO | bigint NOT NULL |
| account_id | text / text | NO | text NOT NULL |
| api_surface | text / text | NO | text NOT NULL |
| model | text / text | NO | text NOT NULL |
| system_hash | bytea / bytea | NO | bytea NOT NULL |
| schema_tool_policy_hash | bytea / bytea | NO | bytea NOT NULL |
| representation_hash | bytea / bytea | NO | bytea NOT NULL |
| content_ordering_hash | bytea / bytea | NO | bytea NOT NULL |
| provider_name | text / text | YES | text |
| expires_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| creation_state | text / text | NO | text NOT NULL |
| creation_token | uuid / uuid | YES | uuid |
| creation_lease_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| cost | numeric / numeric | NO | numeric(24,8) NOT NULL |

Constraints:
- `llm_context_caches_check`: `CHECK (((creation_token IS NULL) = (creation_lease_until IS NULL)))`
- `llm_context_caches_check1`: `CHECK (((creation_state <> 'active'::text) OR ((provider_name IS NOT NULL) AND (expires_at IS NOT NULL))))`
- `llm_context_caches_content_ordering_hash_check`: `CHECK ((octet_length(content_ordering_hash) = 32))`
- `llm_context_caches_cost_check`: `CHECK ((cost >= (0)::numeric))`
- `llm_context_caches_creation_state_check`: `CHECK ((creation_state = ANY (ARRAY['creating'::text, 'active'::text, 'expired'::text])))`
- `llm_context_caches_creation_token_fkey`: `FOREIGN KEY (creation_token) REFERENCES llm_provider_calls(physical_call_id)`
- `llm_context_caches_pkey`: `PRIMARY KEY (id)`
- `llm_context_caches_provider_id_account_id_api_surface_model_key`: `UNIQUE (provider_id, account_id, api_surface, model, system_hash, schema_tool_policy_hash, representation_hash, content_ordering_hash)`
- `llm_context_caches_provider_id_fkey`: `FOREIGN KEY (provider_id) REFERENCES llm_providers(id)`
- `llm_context_caches_representation_hash_check`: `CHECK ((octet_length(representation_hash) = 32))`
- `llm_context_caches_schema_tool_policy_hash_check`: `CHECK ((octet_length(schema_tool_policy_hash) = 32))`
- `llm_context_caches_system_hash_check`: `CHECK ((octet_length(system_hash) = 32))`

Indexes:
- `llm_context_caches_pkey`: `CREATE UNIQUE INDEX llm_context_caches_pkey ON public.llm_context_caches USING btree (id)`
- `llm_context_caches_provider_id_account_id_api_surface_model_key`: `CREATE UNIQUE INDEX llm_context_caches_provider_id_account_id_api_surface_model_key ON public.llm_context_caches USING btree (provider_id, account_id, api_surface, model, system_hash, schema_tool_policy_hash, representation_hash, content_ordering_hash)`

#### llm_file_assets

Owner: Services/Llm/Cache; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| provider_id | bigint / int8 | NO | bigint NOT NULL |
| account_id | text / text | NO | text NOT NULL |
| api_surface | text / text | NO | text NOT NULL |
| content_hash | bytea / bytea | NO | bytea NOT NULL |
| mime | text / text | NO | text NOT NULL |
| provider_uri | text / text | YES | text |
| state | text / text | NO | text NOT NULL |
| expires_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| creation_token | uuid / uuid | YES | uuid |
| creation_lease_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `llm_file_assets_check`: `CHECK (((creation_token IS NULL) = (creation_lease_until IS NULL)))`
- `llm_file_assets_check1`: `CHECK (((state <> ALL (ARRAY['PROCESSING'::text, 'ACTIVE'::text])) OR ((provider_uri IS NOT NULL) AND (expires_at IS NOT NULL))))`
- `llm_file_assets_content_hash_check`: `CHECK ((octet_length(content_hash) = 32))`
- `llm_file_assets_creation_token_fkey`: `FOREIGN KEY (creation_token) REFERENCES llm_provider_calls(physical_call_id)`
- `llm_file_assets_pkey`: `PRIMARY KEY (id)`
- `llm_file_assets_provider_id_account_id_api_surface_content__key`: `UNIQUE (provider_id, account_id, api_surface, content_hash, mime)`
- `llm_file_assets_provider_id_fkey`: `FOREIGN KEY (provider_id) REFERENCES llm_providers(id)`
- `llm_file_assets_state_check`: `CHECK ((state = ANY (ARRAY['creating'::text, 'PROCESSING'::text, 'ACTIVE'::text, 'FAILED'::text, 'expired'::text])))`

Indexes:
- `llm_file_assets_pkey`: `CREATE UNIQUE INDEX llm_file_assets_pkey ON public.llm_file_assets USING btree (id)`
- `llm_file_assets_provider_id_account_id_api_surface_content__key`: `CREATE UNIQUE INDEX llm_file_assets_provider_id_account_id_api_surface_content__key ON public.llm_file_assets USING btree (provider_id, account_id, api_surface, content_hash, mime)`

#### llm_financial_actions

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| account_id | text / text | NO | text NOT NULL |
| currency | text / text | NO | text NOT NULL |
| physical_call_id | uuid / uuid | YES | uuid |
| action | text / text | NO | text NOT NULL |
| actor | text / text | NO | text NOT NULL |
| reason | text / text | NO | text NOT NULL |
| details | jsonb / jsonb | NO | jsonb NOT NULL |
| details_schema_version | smallint / int2 | NO | smallint NOT NULL |
| occurred_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `llm_financial_actions_account_id_currency_fkey`: `FOREIGN KEY (account_id, currency) REFERENCES llm_budget_accounts(account_id, currency)`
- `llm_financial_actions_action_check`: `CHECK ((action = ANY (ARRAY['policy_change'::text, 'conservative_settlement'::text, 'abandon_unknown'::text, 'receipt_reconciliation'::text])))`
- `llm_financial_actions_actor_check`: `CHECK ((length(actor) > 0))`
- `llm_financial_actions_details_schema_version_check`: `CHECK ((details_schema_version = 1))`
- `llm_financial_actions_physical_call_id_fkey`: `FOREIGN KEY (physical_call_id) REFERENCES llm_provider_calls(physical_call_id)`
- `llm_financial_actions_pkey`: `PRIMARY KEY (id)`
- `llm_financial_actions_reason_check`: `CHECK ((length(reason) > 0))`

Indexes:
- `llm_financial_actions_pkey`: `CREATE UNIQUE INDEX llm_financial_actions_pkey ON public.llm_financial_actions USING btree (id)`

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

#### llm_provider_calls

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| physical_call_id | uuid / uuid | NO | uuid NOT NULL |
| logical_request_id | uuid / uuid | NO | uuid NOT NULL |
| ordinal | bigint / int8 | NO | bigint NOT NULL |
| provider_id | bigint / int8 | NO | bigint NOT NULL |
| account_id | text / text | NO | text NOT NULL |
| api_surface | text / text | NO | text NOT NULL |
| model | text / text | NO | text NOT NULL |
| resolved_model_version | text / text | YES | text |
| request_epoch | bigint / int8 | NO | bigint NOT NULL |
| owner_attempt_id | uuid / uuid | NO | uuid NOT NULL |
| owner_work_generation | bigint / int8 | NO | bigint NOT NULL |
| send_authorization_token | uuid / uuid | NO | uuid NOT NULL |
| send_claimed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| sent_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| response_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| wire_fingerprint | bytea / bytea | YES | bytea |
| http_status | smallint / int2 | YES | smallint |
| finish_reason | text / text | YES | text |
| usage_status | text / text | NO | text NOT NULL |
| usage | jsonb / jsonb | YES | jsonb |
| usage_schema_version | smallint / int2 | YES | smallint |
| cost_estimate | numeric / numeric | NO | numeric(24,8) NOT NULL |
| cost_actual | numeric / numeric | YES | numeric(24,8) |
| receipt_id | uuid / uuid | YES | uuid |
| receipt_state | text / text | NO | text NOT NULL |
| receipt_upload_key | text / text | NO | text NOT NULL |
| receipt_disk | text / text | YES | text |
| receipt_key | text / text | YES | text |
| receipt_hash | bytea / bytea | YES | bytea |
| receipt_schema_version | smallint / int2 | YES | smallint |
| reservation_id | uuid / uuid | NO | uuid NOT NULL |
| outcome | text / text | YES | text |
| charge_evidence | text / text | YES | text |

Constraints:
- `llm_provider_calls_check`: `CHECK (((usage IS NULL) = (usage_schema_version IS NULL)))`
- `llm_provider_calls_check1`: `CHECK (((receipt_key IS NULL) = (receipt_hash IS NULL)))`
- `llm_provider_calls_check2`: `CHECK (((receipt_key IS NULL) = (receipt_schema_version IS NULL)))`
- `llm_provider_calls_check3`: `CHECK (((receipt_state = 'verified'::text) = (receipt_key IS NOT NULL)))`
- `llm_provider_calls_check4`: `CHECK (((receipt_state <> ALL (ARRAY['verified'::text, 'unknown'::text])) OR (send_claimed_at IS NOT NULL)))`
- `llm_provider_calls_check5`: `CHECK (((receipt_state <> 'not_sent'::text) OR ((send_claimed_at IS NULL) AND (cost_actual = (0)::numeric))))`
- `llm_provider_calls_check6`: `CHECK (((receipt_key IS NULL) OR (receipt_key = receipt_upload_key)))`
- `llm_provider_calls_cost_actual_check`: `CHECK ((cost_actual >= (0)::numeric))`
- `llm_provider_calls_cost_estimate_check`: `CHECK ((cost_estimate >= (0)::numeric))`
- `llm_provider_calls_http_status_check`: `CHECK (((http_status >= 100) AND (http_status <= 599)))`
- `llm_provider_calls_logical_request_id_fkey`: `FOREIGN KEY (logical_request_id) REFERENCES llm_provider_requests(logical_request_id)`
- `llm_provider_calls_logical_request_id_ordinal_key`: `UNIQUE (logical_request_id, ordinal)`
- `llm_provider_calls_ordinal_check`: `CHECK ((ordinal >= 1))`
- `llm_provider_calls_outcome_check`: `CHECK ((outcome = ANY (ARRAY['not_sent'::text, 'success'::text, 'known_nonbillable_cache_miss'::text, 'known_retryable_nonbillable'::text, 'billed_error'::text, 'unknown'::text])))`
- `llm_provider_calls_owner_work_generation_check`: `CHECK ((owner_work_generation >= 1))`
- `llm_provider_calls_physical_call_id_logical_request_id_key`: `UNIQUE (physical_call_id, logical_request_id)`
- `llm_provider_calls_pkey`: `PRIMARY KEY (physical_call_id)`
- `llm_provider_calls_provider_id_fkey`: `FOREIGN KEY (provider_id) REFERENCES llm_providers(id)`
- `llm_provider_calls_receipt_hash_check`: `CHECK ((octet_length(receipt_hash) = 32))`
- `llm_provider_calls_receipt_id_key`: `UNIQUE (receipt_id)`
- `llm_provider_calls_receipt_schema_version_check`: `CHECK ((receipt_schema_version = 1))`
- `llm_provider_calls_receipt_state_check`: `CHECK ((receipt_state = ANY (ARRAY['pending'::text, 'verified'::text, 'not_sent'::text, 'unknown'::text])))`
- `llm_provider_calls_receipt_upload_key_key`: `UNIQUE (receipt_upload_key)`
- `llm_provider_calls_request_epoch_check`: `CHECK ((request_epoch >= 1))`
- `llm_provider_calls_reservation_fk`: `FOREIGN KEY (reservation_id, physical_call_id) REFERENCES llm_budget_reservations(id, operation_id) DEFERRABLE INITIALLY DEFERRED`
- `llm_provider_calls_reservation_id_key`: `UNIQUE (reservation_id)`
- `llm_provider_calls_usage_schema_version_check`: `CHECK ((usage_schema_version > 0))`
- `llm_provider_calls_usage_status_check`: `CHECK ((usage_status = ANY (ARRAY['known'::text, 'unknown'::text])))`
- `llm_provider_calls_wire_fingerprint_check`: `CHECK ((octet_length(wire_fingerprint) = 32))`

Indexes:
- `llm_provider_calls_account_idx`: `CREATE INDEX llm_provider_calls_account_idx ON public.llm_provider_calls USING btree (account_id, logical_request_id)`
- `llm_provider_calls_logical_request_id_ordinal_key`: `CREATE UNIQUE INDEX llm_provider_calls_logical_request_id_ordinal_key ON public.llm_provider_calls USING btree (logical_request_id, ordinal)`
- `llm_provider_calls_physical_call_id_logical_request_id_key`: `CREATE UNIQUE INDEX llm_provider_calls_physical_call_id_logical_request_id_key ON public.llm_provider_calls USING btree (physical_call_id, logical_request_id)`
- `llm_provider_calls_pkey`: `CREATE UNIQUE INDEX llm_provider_calls_pkey ON public.llm_provider_calls USING btree (physical_call_id)`
- `llm_provider_calls_receipt_id_key`: `CREATE UNIQUE INDEX llm_provider_calls_receipt_id_key ON public.llm_provider_calls USING btree (receipt_id)`
- `llm_provider_calls_receipt_upload_key_key`: `CREATE UNIQUE INDEX llm_provider_calls_receipt_upload_key_key ON public.llm_provider_calls USING btree (receipt_upload_key)`
- `llm_provider_calls_reservation_id_key`: `CREATE UNIQUE INDEX llm_provider_calls_reservation_id_key ON public.llm_provider_calls USING btree (reservation_id)`
- `llm_provider_calls_send_time_idx`: `CREATE INDEX llm_provider_calls_send_time_idx ON public.llm_provider_calls USING btree (COALESCE(sent_at, send_claimed_at))`

#### llm_provider_requests

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| logical_request_id | uuid / uuid | NO | uuid NOT NULL |
| logical_key | bytea / bytea | NO | bytea NOT NULL |
| recipe_hash | bytea / bytea | NO | bytea NOT NULL |
| input_hash | bytea / bytea | NO | bytea NOT NULL |
| purpose | text / text | NO | text NOT NULL |
| semantic_reask_ordinal | bigint / int8 | NO | bigint NOT NULL |
| replay_intent_id | uuid / uuid | NO | uuid NOT NULL |
| replay_of | uuid / uuid | YES | uuid |
| command_id | uuid / uuid | YES | uuid |
| replay_actor | text / text | YES | text |
| replay_reason | text / text | YES | text |
| state | text / text | NO | text NOT NULL |
| request_epoch | bigint / int8 | NO | bigint NOT NULL |
| idempotency_key | text / text | YES | text |
| consumer_correlation_id | text / text | NO | text NOT NULL |
| reservation_amount | numeric / numeric | NO | numeric(24,8) DEFAULT 0 NOT NULL |
| context_json | jsonb / jsonb | NO | jsonb NOT NULL |
| context_schema_version | smallint / int2 | NO | smallint NOT NULL |
| max_physical_attempts | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `llm_provider_requests_check`: `CHECK (((replay_of IS NULL) = (command_id IS NULL)))`
- `llm_provider_requests_check1`: `CHECK (((command_id IS NULL) = (replay_actor IS NULL)))`
- `llm_provider_requests_check2`: `CHECK (((command_id IS NULL) = (replay_reason IS NULL)))`
- `llm_provider_requests_command_id_key`: `UNIQUE (command_id)`
- `llm_provider_requests_context_schema_version_check`: `CHECK ((context_schema_version = 1))`
- `llm_provider_requests_input_hash_check`: `CHECK ((octet_length(input_hash) = 32))`
- `llm_provider_requests_logical_key_check`: `CHECK ((octet_length(logical_key) = 32))`
- `llm_provider_requests_logical_key_key`: `UNIQUE (logical_key)`
- `llm_provider_requests_max_physical_attempts_check`: `CHECK (((max_physical_attempts >= 1) AND (max_physical_attempts <= 10)))`
- `llm_provider_requests_pkey`: `PRIMARY KEY (logical_request_id)`
- `llm_provider_requests_purpose_check`: `CHECK ((purpose = ANY (ARRAY['generate'::text, 'cache_create'::text, 'cache_refresh'::text, 'cache_storage'::text, 'file_upload'::text, 'lookup'::text])))`
- `llm_provider_requests_recipe_hash_check`: `CHECK ((octet_length(recipe_hash) = 32))`
- `llm_provider_requests_replay_of_fkey`: `FOREIGN KEY (replay_of) REFERENCES llm_provider_requests(logical_request_id)`
- `llm_provider_requests_request_epoch_check`: `CHECK ((request_epoch >= 1))`
- `llm_provider_requests_reservation_amount_check`: `CHECK ((reservation_amount >= (0)::numeric))`
- `llm_provider_requests_semantic_reask_ordinal_check`: `CHECK (((semantic_reask_ordinal >= 0) AND (semantic_reask_ordinal <= 1)))`
- `llm_provider_requests_state_check`: `CHECK ((state = ANY (ARRAY['prepared'::text, 'sending'::text, 'completed'::text, 'failed_known'::text, 'unknown'::text, 'abandoned_unknown'::text])))`

Indexes:
- `llm_provider_requests_command_id_key`: `CREATE UNIQUE INDEX llm_provider_requests_command_id_key ON public.llm_provider_requests USING btree (command_id)`
- `llm_provider_requests_logical_key_key`: `CREATE UNIQUE INDEX llm_provider_requests_logical_key_key ON public.llm_provider_requests USING btree (logical_key)`
- `llm_provider_requests_pkey`: `CREATE UNIQUE INDEX llm_provider_requests_pkey ON public.llm_provider_requests USING btree (logical_request_id)`
- `llm_provider_requests_unknown_state_idx`: `CREATE INDEX llm_provider_requests_unknown_state_idx ON public.llm_provider_requests USING btree (logical_request_id) WHERE (state = ANY (ARRAY['unknown'::text, 'abandoned_unknown'::text]))`

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
| attributes_observed_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |

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

#### prc_acquisition_schedules

Owner: AcquisitionScheduler; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| profile_id | bigint / int8 | NO | bigint NOT NULL |
| acquisition_kind | text / text | NO | text NOT NULL |
| cron | text / text | NO | text NOT NULL |
| timezone | text / text | NO | text NOT NULL |
| profile_version | bigint / int8 | NO | bigint NOT NULL |
| next_due_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_acquisition_schedules_acquisition_kind_check`: `CHECK ((acquisition_kind = ANY (ARRAY['fresh'::text, 'archive'::text, 'delta'::text])))`
- `prc_acquisition_schedules_cron_check`: `CHECK (((length(cron) >= 1) AND (length(cron) <= 200)))`
- `prc_acquisition_schedules_pkey`: `PRIMARY KEY (profile_id, acquisition_kind)`
- `prc_acquisition_schedules_profile_id_fkey`: `FOREIGN KEY (profile_id) REFERENCES prc_crawlers(id)`
- `prc_acquisition_schedules_profile_version_check`: `CHECK ((profile_version > 0))`
- `prc_acquisition_schedules_timezone_check`: `CHECK (((length(timezone) >= 1) AND (length(timezone) <= 100)))`

Indexes:
- `prc_acquisition_schedules_due_idx`: `CREATE INDEX prc_acquisition_schedules_due_idx ON public.prc_acquisition_schedules USING btree (next_due_at, profile_id, acquisition_kind)`
- `prc_acquisition_schedules_pkey`: `CREATE UNIQUE INDEX prc_acquisition_schedules_pkey ON public.prc_acquisition_schedules USING btree (profile_id, acquisition_kind)`

#### prc_adapter_health

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| capability | text / text | NO | text NOT NULL |
| adapter_version | text / text | NO | text NOT NULL |
| contract_version | text / text | NO | text NOT NULL |
| state | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| state_version | bigint / int8 | NO | bigint NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| recent_openings | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_adapter_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `prc_adapter_health_pkey`: `PRIMARY KEY (id)`
- `prc_adapter_health_platform_id_capability_adapter_version_c_key`: `UNIQUE (platform_id, capability, adapter_version, contract_version)`
- `prc_adapter_health_platform_id_fkey`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_adapter_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `prc_adapter_health_state_check`: `CHECK ((state = ANY (ARRAY['healthy'::text, 'suspect'::text, 'held'::text, 'validating'::text])))`
- `prc_adapter_health_state_version_check`: `CHECK ((state_version >= 1))`

Indexes:
- `prc_adapter_health_pkey`: `CREATE UNIQUE INDEX prc_adapter_health_pkey ON public.prc_adapter_health USING btree (id)`
- `prc_adapter_health_platform_id_capability_adapter_version_c_key`: `CREATE UNIQUE INDEX prc_adapter_health_platform_id_capability_adapter_version_c_key ON public.prc_adapter_health USING btree (platform_id, capability, adapter_version, contract_version)`

#### prc_admission_groups

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | NO | uuid NOT NULL |
| dispatch_generation | bigint / int8 | NO | bigint NOT NULL |
| required_lane_set_hash | bytea / bytea | NO | bytea NOT NULL |
| attempt_id | uuid / uuid | YES | uuid |
| state | text / text | NO | text NOT NULL |
| version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| admitted_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| released_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| release_reason | text / text | YES | text |

Constraints:
- `prc_admission_groups_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_admission_groups_dispatch_generation_check`: `CHECK ((dispatch_generation >= 1))`
- `prc_admission_groups_pkey`: `PRIMARY KEY (id)`
- `prc_admission_groups_required_lane_set_hash_check`: `CHECK ((octet_length(required_lane_set_hash) = 32))`
- `prc_admission_groups_state_check`: `CHECK ((state = ANY (ARRAY['admitted'::text, 'running'::text, 'released'::text])))`
- `prc_admission_groups_version_check`: `CHECK ((version >= 1))`
- `prc_admission_groups_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_admission_groups_active_work_unique`: `CREATE UNIQUE INDEX prc_admission_groups_active_work_unique ON public.prc_admission_groups USING btree (work_id) WHERE (state <> 'released'::text)`
- `prc_admission_groups_pkey`: `CREATE UNIQUE INDEX prc_admission_groups_pkey ON public.prc_admission_groups USING btree (id)`

#### prc_ai_analyses

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| analysis_run_id | uuid / uuid | YES | uuid |
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
| started_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
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
- `prc_ai_analyses_analysis_run_fk`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_ai_analyses_document_type_check`: `CHECK (((document_type IS NULL) OR (document_type = ANY (ARRAY['estimate'::text, 'tech_spec'::text, 'contract_draft'::text, 'protocol'::text, 'unknown'::text]))))`
- `prc_ai_analyses_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_ai_analyses_parent_analysis_id_foreign`: `FOREIGN KEY (parent_analysis_id) REFERENCES prc_ai_analyses(id) ON DELETE SET NULL`
- `prc_ai_analyses_pkey`: `PRIMARY KEY (id)`
- `prc_ai_analyses_result_status_check`: `CHECK ((status = ANY (ARRAY['succeeded'::text, 'skipped'::text])))`
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
| llm_call_id | uuid / uuid | YES | uuid |
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
- `prc_ai_prompt_suggestions_call_fk`: `FOREIGN KEY (llm_call_id) REFERENCES llm_provider_calls(physical_call_id)`
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
- `prc_ai_prompt_suggestions_llm_call_id_index`: `CREATE INDEX prc_ai_prompt_suggestions_llm_call_id_index ON public.prc_ai_prompt_suggestions USING btree (llm_call_id)`
- `prc_ai_prompt_suggestions_pkey`: `CREATE UNIQUE INDEX prc_ai_prompt_suggestions_pkey ON public.prc_ai_prompt_suggestions USING btree (id)`
- `prc_ai_prompt_suggestions_prompt_key_index`: `CREATE INDEX prc_ai_prompt_suggestions_prompt_key_index ON public.prc_ai_prompt_suggestions USING btree (prompt_key)`
- `prc_ai_prompt_suggestions_status_index`: `CREATE INDEX prc_ai_prompt_suggestions_status_index ON public.prc_ai_prompt_suggestions USING btree (status)`
- `prc_ai_prompt_suggestions_uuid_unique`: `CREATE UNIQUE INDEX prc_ai_prompt_suggestions_uuid_unique ON public.prc_ai_prompt_suggestions USING btree (uuid)`

#### prc_analysis_chunks

Owner: P3.8 canonical analysis / Repositories/Procurement/Ai; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| run_id | uuid / uuid | NO | uuid NOT NULL |
| window_id | uuid / uuid | NO | uuid NOT NULL |
| window_key | text / text | NO | text NOT NULL |
| recipe_hash | bytea / bytea | NO | bytea NOT NULL |
| subrange_key | bytea / bytea | NO | bytea NOT NULL |
| output_ordinal | bigint / int8 | NO | bigint NOT NULL |
| output_ref | jsonb / jsonb | NO | jsonb NOT NULL |
| output_ref_schema_version | smallint / int2 | NO | smallint NOT NULL |
| output_hash | bytea / bytea | NO | bytea NOT NULL |
| canonical_rows | jsonb / jsonb | NO | jsonb NOT NULL |
| canonical_rows_schema_version | smallint / int2 | NO | smallint NOT NULL |
| provenance | jsonb / jsonb | NO | jsonb NOT NULL |
| provenance_schema_version | smallint / int2 | NO | smallint NOT NULL |
| completed_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_analysis_chunks_canonical_rows_schema_version_check`: `CHECK ((canonical_rows_schema_version > 0))`
- `prc_analysis_chunks_output_hash_check`: `CHECK ((octet_length(output_hash) = 32))`
- `prc_analysis_chunks_output_ordinal_check`: `CHECK ((output_ordinal >= 0))`
- `prc_analysis_chunks_output_ref_schema_version_check`: `CHECK ((output_ref_schema_version > 0))`
- `prc_analysis_chunks_pkey`: `PRIMARY KEY (id)`
- `prc_analysis_chunks_provenance_schema_version_check`: `CHECK ((provenance_schema_version > 0))`
- `prc_analysis_chunks_recipe_fk`: `FOREIGN KEY (run_id, recipe_hash) REFERENCES prc_analysis_runs(id, recipe_hash)`
- `prc_analysis_chunks_recipe_hash_check`: `CHECK ((octet_length(recipe_hash) = 32))`
- `prc_analysis_chunks_run_id_fkey`: `FOREIGN KEY (run_id) REFERENCES prc_analysis_runs(id)`
- `prc_analysis_chunks_run_id_window_key_recipe_hash_subrange__key`: `UNIQUE (run_id, window_key, recipe_hash, subrange_key, output_ordinal)`
- `prc_analysis_chunks_subrange_key_check`: `CHECK ((octet_length(subrange_key) = 32))`
- `prc_analysis_chunks_window_id_fkey`: `FOREIGN KEY (window_id) REFERENCES prc_analysis_windows(id)`
- `prc_analysis_chunks_window_identity_fk`: `FOREIGN KEY (window_id, run_id, window_key) REFERENCES prc_analysis_windows(id, run_id, window_key)`

Indexes:
- `prc_analysis_chunks_pkey`: `CREATE UNIQUE INDEX prc_analysis_chunks_pkey ON public.prc_analysis_chunks USING btree (id)`
- `prc_analysis_chunks_run_id_window_key_recipe_hash_subrange__key`: `CREATE UNIQUE INDEX prc_analysis_chunks_run_id_window_key_recipe_hash_subrange__key ON public.prc_analysis_chunks USING btree (run_id, window_key, recipe_hash, subrange_key, output_ordinal)`

#### prc_analysis_llm_requests

Owner: P3.9; retention: financial.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| analysis_run_id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | NO | uuid NOT NULL |
| attempt_id | uuid / uuid | NO | uuid NOT NULL |
| logical_request_id | uuid / uuid | NO | uuid NOT NULL |
| physical_call_id | uuid / uuid | NO | uuid NOT NULL |
| expected_input_hash | bytea / bytea | NO | bytea NOT NULL |
| recipe_hash | bytea / bytea | NO | bytea NOT NULL |
| recovery_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |

Constraints:
- `prc_analysis_llm_requests_analysis_run_id_fkey`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_analysis_llm_requests_analysis_run_id_recipe_hash_fkey`: `FOREIGN KEY (analysis_run_id, recipe_hash) REFERENCES prc_analysis_runs(id, recipe_hash)`
- `prc_analysis_llm_requests_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_analysis_llm_requests_expected_input_hash_check`: `CHECK ((octet_length(expected_input_hash) = 32))`
- `prc_analysis_llm_requests_logical_request_id_fkey`: `FOREIGN KEY (logical_request_id) REFERENCES llm_provider_requests(logical_request_id)`
- `prc_analysis_llm_requests_physical_call_id_fkey`: `FOREIGN KEY (physical_call_id) REFERENCES llm_provider_calls(physical_call_id)`
- `prc_analysis_llm_requests_physical_call_id_key`: `UNIQUE (physical_call_id)`
- `prc_analysis_llm_requests_physical_call_id_logical_request_fkey`: `FOREIGN KEY (physical_call_id, logical_request_id) REFERENCES llm_provider_calls(physical_call_id, logical_request_id)`
- `prc_analysis_llm_requests_pkey`: `PRIMARY KEY (id)`
- `prc_analysis_llm_requests_recipe_hash_check`: `CHECK ((octet_length(recipe_hash) = 32))`
- `prc_analysis_llm_requests_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_analysis_llm_active_work`: `CREATE UNIQUE INDEX prc_analysis_llm_active_work ON public.prc_analysis_llm_requests USING btree (work_id) WHERE recovery_active`
- `prc_analysis_llm_requests_physical_call_id_key`: `CREATE UNIQUE INDEX prc_analysis_llm_requests_physical_call_id_key ON public.prc_analysis_llm_requests USING btree (physical_call_id)`
- `prc_analysis_llm_requests_pkey`: `CREATE UNIQUE INDEX prc_analysis_llm_requests_pkey ON public.prc_analysis_llm_requests USING btree (id)`
- `prc_analysis_llm_requests_run_call_idx`: `CREATE INDEX prc_analysis_llm_requests_run_call_idx ON public.prc_analysis_llm_requests USING btree (analysis_run_id, physical_call_id)`
- `prc_analysis_llm_work_idx`: `CREATE INDEX prc_analysis_llm_work_idx ON public.prc_analysis_llm_requests USING btree (work_id, attempt_id)`

#### prc_analysis_runs

Owner: P3.8 canonical analysis / Repositories/Procurement/Ai; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| representation_id | bigint / int8 | NO | bigint NOT NULL |
| representation_hash | bytea / bytea | NO | bytea NOT NULL |
| recipe_json | jsonb / jsonb | NO | jsonb NOT NULL |
| recipe_schema_version | smallint / int2 | NO | smallint NOT NULL |
| recipe_hash | bytea / bytea | NO | bytea NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| expected_document_head_generation | bigint / int8 | NO | bigint NOT NULL |
| expected_analysis_generation | bigint / int8 | NO | bigint NOT NULL |
| requested_reason | text / text | NO | text NOT NULL |
| supersedes_run_id | uuid / uuid | YES | uuid |
| publication_status | text / text | NO | text NOT NULL |
| screening_result | jsonb / jsonb | YES | jsonb |
| screening_result_schema_version | smallint / int2 | YES | smallint |
| screening_disposition | text / text | YES | text |
| declared_coverage | jsonb / jsonb | YES | jsonb |
| declared_coverage_schema_version | smallint / int2 | YES | smallint |
| publication_digest | bytea / bytea | YES | bytea |

Constraints:
- `prc_analysis_runs_coverage_pair`: `CHECK (((declared_coverage IS NULL) = (declared_coverage_schema_version IS NULL)))`
- `prc_analysis_runs_coverage_schema`: `CHECK (((declared_coverage_schema_version IS NULL) OR (declared_coverage_schema_version = ANY (ARRAY[1, 2]))))`
- `prc_analysis_runs_coverage_source`: `CHECK (((declared_coverage_schema_version IS DISTINCT FROM 2) OR ((NOT (jsonb_typeof((declared_coverage -> 'source'::text)) IS DISTINCT FROM 'object'::text)) AND ((NOT (jsonb_typeof((declared_coverage -> 'estimates'::text)) IS DISTINCT FROM 'null'::text)) OR (NOT (jsonb_typeof((declared_coverage -> 'estimates'::text)) IS DISTINCT FROM 'array'::text))))))`
- `prc_analysis_runs_document_version_id_fkey`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_analysis_runs_expected_analysis_generation_check`: `CHECK ((expected_analysis_generation >= 1))`
- `prc_analysis_runs_expected_document_head_generation_check`: `CHECK ((expected_document_head_generation >= 1))`
- `prc_analysis_runs_generation_check`: `CHECK ((generation >= 1))`
- `prc_analysis_runs_id_recipe_unique`: `UNIQUE (id, recipe_hash)`
- `prc_analysis_runs_pkey`: `PRIMARY KEY (id)`
- `prc_analysis_runs_publication_check`: `CHECK ((publication_status = ANY (ARRAY['unpublished'::text, 'published'::text])))`
- `prc_analysis_runs_publication_digest_check`: `CHECK (((publication_digest IS NULL) OR (octet_length(publication_digest) = 32)))`
- `prc_analysis_runs_publication_pair`: `CHECK (((publication_status = 'published'::text) = (publication_digest IS NOT NULL)))`
- `prc_analysis_runs_recipe_hash_check`: `CHECK ((octet_length(recipe_hash) = 32))`
- `prc_analysis_runs_recipe_schema_version_check`: `CHECK ((recipe_schema_version > 0))`
- `prc_analysis_runs_representation_hash_check`: `CHECK ((octet_length(representation_hash) = 32))`
- `prc_analysis_runs_representation_id_fkey`: `FOREIGN KEY (representation_id) REFERENCES prc_document_representations(id)`
- `prc_analysis_runs_representation_identity_fk`: `FOREIGN KEY (representation_id, document_version_id, representation_hash) REFERENCES prc_document_representations(id, document_version_id, content_hash)`
- `prc_analysis_runs_screening_disposition_check`: `CHECK ((screening_disposition = ANY (ARRAY['extract'::text, 'retain'::text, 'review'::text, 'ignore'::text])))`
- `prc_analysis_runs_screening_disposition_pair`: `CHECK (((screening_result IS NULL) = (screening_disposition IS NULL)))`
- `prc_analysis_runs_screening_schema_check`: `CHECK (((screening_result_schema_version IS NULL) OR (screening_result_schema_version = 1)))`
- `prc_analysis_runs_screening_schema_pair`: `CHECK (((screening_result IS NULL) = (screening_result_schema_version IS NULL)))`
- `prc_analysis_runs_supersedes_run_id_fkey`: `FOREIGN KEY (supersedes_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_analysis_runs_version_identity_unique`: `UNIQUE (id, document_version_id)`

Indexes:
- `prc_analysis_runs_document_version_idx`: `CREATE INDEX prc_analysis_runs_document_version_idx ON public.prc_analysis_runs USING btree (document_version_id)`
- `prc_analysis_runs_id_recipe_unique`: `CREATE UNIQUE INDEX prc_analysis_runs_id_recipe_unique ON public.prc_analysis_runs USING btree (id, recipe_hash)`
- `prc_analysis_runs_pkey`: `CREATE UNIQUE INDEX prc_analysis_runs_pkey ON public.prc_analysis_runs USING btree (id)`
- `prc_analysis_runs_version_identity_unique`: `CREATE UNIQUE INDEX prc_analysis_runs_version_identity_unique ON public.prc_analysis_runs USING btree (id, document_version_id)`

#### prc_analysis_windows

Owner: P3.8 canonical analysis / Repositories/Procurement/Ai; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| run_id | uuid / uuid | NO | uuid NOT NULL |
| window_key | text / text | NO | text NOT NULL |
| input_hash | bytea / bytea | NO | bytea NOT NULL |
| input_range | jsonb / jsonb | NO | jsonb NOT NULL |
| input_range_schema_version | smallint / int2 | NO | smallint NOT NULL |
| expected_coverage | jsonb / jsonb | NO | jsonb NOT NULL |
| expected_coverage_schema_version | smallint / int2 | NO | smallint NOT NULL |
| state | text / text | NO | text NOT NULL |
| checkpoint_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_analysis_windows_checkpoint_version_check`: `CHECK ((checkpoint_version >= 0))`
- `prc_analysis_windows_expected_coverage_schema_version_check`: `CHECK ((expected_coverage_schema_version > 0))`
- `prc_analysis_windows_identity_unique`: `UNIQUE (id, run_id, window_key)`
- `prc_analysis_windows_input_hash_check`: `CHECK ((octet_length(input_hash) = 32))`
- `prc_analysis_windows_input_range_schema_version_check`: `CHECK ((input_range_schema_version > 0))`
- `prc_analysis_windows_pkey`: `PRIMARY KEY (id)`
- `prc_analysis_windows_run_id_fkey`: `FOREIGN KEY (run_id) REFERENCES prc_analysis_runs(id)`
- `prc_analysis_windows_run_id_window_key_key`: `UNIQUE (run_id, window_key)`

Indexes:
- `prc_analysis_windows_identity_unique`: `CREATE UNIQUE INDEX prc_analysis_windows_identity_unique ON public.prc_analysis_windows USING btree (id, run_id, window_key)`
- `prc_analysis_windows_pkey`: `CREATE UNIQUE INDEX prc_analysis_windows_pkey ON public.prc_analysis_windows USING btree (id)`
- `prc_analysis_windows_run_id_window_key_key`: `CREATE UNIQUE INDEX prc_analysis_windows_run_id_window_key_key ON public.prc_analysis_windows USING btree (run_id, window_key)`

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

#### prc_control_scopes

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| scope_kind | text / text | NO | text NOT NULL |
| scope_key | text / text | NO | text NOT NULL |
| parent_id | uuid / uuid | YES | uuid |
| desired_mode | text / text | NO | text DEFAULT 'enabled'::text NOT NULL |
| control_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| changed_by | bigint / int8 | YES | bigint |
| change_reason | text / text | YES | text |
| changed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_control_scopes_changed_by_fkey`: `FOREIGN KEY (changed_by) REFERENCES users(id)`
- `prc_control_scopes_control_version_check`: `CHECK ((control_version >= 1))`
- `prc_control_scopes_desired_mode_check`: `CHECK ((desired_mode = ANY (ARRAY['enabled'::text, 'paused'::text])))`
- `prc_control_scopes_parent_id_fkey`: `FOREIGN KEY (parent_id) REFERENCES prc_control_scopes(id)`
- `prc_control_scopes_pkey`: `PRIMARY KEY (id)`
- `prc_control_scopes_root_check`: `CHECK (((scope_kind = 'system'::text) = (parent_id IS NULL)))`
- `prc_control_scopes_scope_key_unique`: `UNIQUE (scope_key)`
- `prc_control_scopes_scope_kind_check`: `CHECK ((scope_kind = ANY (ARRAY['system'::text, 'acquisition'::text, 'documents'::text, 'platform'::text, 'provider'::text, 'cohort'::text, 'stage'::text])))`

Indexes:
- `prc_control_scopes_pkey`: `CREATE UNIQUE INDEX prc_control_scopes_pkey ON public.prc_control_scopes USING btree (id)`
- `prc_control_scopes_scope_key_unique`: `CREATE UNIQUE INDEX prc_control_scopes_scope_key_unique ON public.prc_control_scopes USING btree (scope_key)`

#### prc_crawl_blocks

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| crawl_run_id | uuid / uuid | NO | uuid NOT NULL |
| parent_block_id | uuid / uuid | YES | uuid |
| block_key | text / text | NO | text NOT NULL |
| partition_payload | jsonb / jsonb | NO | jsonb NOT NULL |
| partition_schema_version | smallint / int2 | NO | smallint NOT NULL |
| filter_hash | bytea / bytea | NO | bytea NOT NULL |
| head_generation | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| input_revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| cursor | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| cursor_schema_version | smallint / int2 | NO | smallint DEFAULT 1 NOT NULL |
| terminal_proof | jsonb / jsonb | YES | jsonb |
| terminal_proof_schema_version | smallint / int2 | YES | smallint |
| limitation_reason_code | text / text | YES | text |

Constraints:
- `prc_crawl_blocks_crawl_run_id_fkey`: `FOREIGN KEY (crawl_run_id) REFERENCES prc_crawl_runs(id)`
- `prc_crawl_blocks_cursor_schema_version_check`: `CHECK ((cursor_schema_version > 0))`
- `prc_crawl_blocks_filter_hash_check`: `CHECK ((octet_length(filter_hash) = 32))`
- `prc_crawl_blocks_head_generation_check`: `CHECK ((head_generation >= 1))`
- `prc_crawl_blocks_input_revision_check`: `CHECK ((input_revision >= 1))`
- `prc_crawl_blocks_parent_block_id_fkey`: `FOREIGN KEY (parent_block_id) REFERENCES prc_crawl_blocks(id)`
- `prc_crawl_blocks_partition_schema_version_check`: `CHECK ((partition_schema_version > 0))`
- `prc_crawl_blocks_pkey`: `PRIMARY KEY (id)`
- `prc_crawl_blocks_proof_schema_check`: `CHECK (((terminal_proof IS NULL) = (terminal_proof_schema_version IS NULL)))`
- `prc_crawl_blocks_run_key_unique`: `UNIQUE (crawl_run_id, block_key)`
- `prc_crawl_blocks_terminal_proof_schema_version_check`: `CHECK ((terminal_proof_schema_version > 0))`

Indexes:
- `prc_crawl_blocks_pkey`: `CREATE UNIQUE INDEX prc_crawl_blocks_pkey ON public.prc_crawl_blocks USING btree (id)`
- `prc_crawl_blocks_run_key_unique`: `CREATE UNIQUE INDEX prc_crawl_blocks_run_key_unique ON public.prc_crawl_blocks USING btree (crawl_run_id, block_key)`

#### prc_crawl_runs

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| process_run_id | uuid / uuid | NO | uuid NOT NULL |
| listing_kind | text / text | NO | text NOT NULL |
| filter_payload | jsonb / jsonb | NO | jsonb NOT NULL |
| filter_schema_version | smallint / int2 | NO | smallint NOT NULL |
| filter_hash | bytea / bytea | NO | bytea NOT NULL |
| access_policy_hash | bytea / bytea | NO | bytea NOT NULL |
| adapter_version | text / text | NO | text NOT NULL |

Constraints:
- `prc_crawl_runs_access_policy_hash_check`: `CHECK ((octet_length(access_policy_hash) = 32))`
- `prc_crawl_runs_filter_hash_check`: `CHECK ((octet_length(filter_hash) = 32))`
- `prc_crawl_runs_filter_schema_version_check`: `CHECK ((filter_schema_version > 0))`
- `prc_crawl_runs_listing_kind_check`: `CHECK ((listing_kind = ANY (ARRAY['fresh'::text, 'archive'::text, 'delta'::text])))`
- `prc_crawl_runs_pkey`: `PRIMARY KEY (id)`
- `prc_crawl_runs_process_run_id_fkey`: `FOREIGN KEY (process_run_id) REFERENCES prc_process_runs(id)`
- `prc_crawl_runs_process_run_id_unique`: `UNIQUE (process_run_id)`

Indexes:
- `prc_crawl_runs_pkey`: `CREATE UNIQUE INDEX prc_crawl_runs_pkey ON public.prc_crawl_runs USING btree (id)`
- `prc_crawl_runs_process_run_id_unique`: `CREATE UNIQUE INDEX prc_crawl_runs_process_run_id_unique ON public.prc_crawl_runs USING btree (process_run_id)`

#### prc_crawlers

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| registry_id | bigint / int8 | NO | bigint NOT NULL |
| name | text / text | NO | text NOT NULL |
| profile_version | bigint / int8 | NO | bigint DEFAULT '1'::bigint NOT NULL |
| listing_kind | text / text | NO | text DEFAULT 'fresh'::text NOT NULL |
| filter | jsonb / jsonb | YES | jsonb |
| schedule | text / text | YES | text |
| route_profile_id | uuid / uuid | YES | uuid |
| is_active | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_crawlers_listing_kind_check`: `CHECK ((listing_kind = ANY (ARRAY['fresh'::text, 'archive'::text, 'delta'::text])))`
- `prc_crawlers_pkey`: `PRIMARY KEY (id)`
- `prc_crawlers_registry_id_foreign`: `FOREIGN KEY (registry_id) REFERENCES prc_registries(id)`
- `prc_crawlers_route_profile_id_foreign`: `FOREIGN KEY (route_profile_id) REFERENCES prc_network_route_profiles(id)`

Indexes:
- `prc_crawlers_pkey`: `CREATE UNIQUE INDEX prc_crawlers_pkey ON public.prc_crawlers USING btree (id)`
- `prc_crawlers_registry_id_index`: `CREATE INDEX prc_crawlers_registry_id_index ON public.prc_crawlers USING btree (registry_id)`

#### prc_detail_snapshots

Owner: Repositories/Procurement/Crawler; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| input_revision | bigint / int8 | NO | bigint NOT NULL |
| part_key | text / text | NO | text NOT NULL |
| completeness | text / text | NO | text NOT NULL |
| result_ref | jsonb / jsonb | NO | jsonb NOT NULL |
| result_ref_schema_version | smallint / int2 | NO | smallint NOT NULL |
| source_version | text / text | YES | text |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_detail_snapshots_completeness_check`: `CHECK ((completeness = ANY (ARRAY['complete'::text, 'complete_empty'::text, 'partial'::text, 'unavailable'::text, 'not_applicable'::text])))`
- `prc_detail_snapshots_input_revision_check`: `CHECK ((input_revision >= 1))`
- `prc_detail_snapshots_part_key_check`: `CHECK (((char_length(part_key) >= 1) AND (char_length(part_key) <= 160)))`
- `prc_detail_snapshots_pkey`: `PRIMARY KEY (id)`
- `prc_detail_snapshots_procurement_id_fkey`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id)`
- `prc_detail_snapshots_procurement_id_input_revision_part_key_key`: `UNIQUE (procurement_id, input_revision, part_key)`
- `prc_detail_snapshots_result_ref_check`: `CHECK ((jsonb_typeof(result_ref) = 'object'::text))`
- `prc_detail_snapshots_result_ref_schema_version_check`: `CHECK ((result_ref_schema_version = 1))`

Indexes:
- `prc_detail_snapshots_pkey`: `CREATE UNIQUE INDEX prc_detail_snapshots_pkey ON public.prc_detail_snapshots USING btree (id)`
- `prc_detail_snapshots_procurement_id_input_revision_part_key_key`: `CREATE UNIQUE INDEX prc_detail_snapshots_procurement_id_input_revision_part_key_key ON public.prc_detail_snapshots USING btree (procurement_id, input_revision, part_key)`
- `prc_detail_snapshots_published_idx`: `CREATE INDEX prc_detail_snapshots_published_idx ON public.prc_detail_snapshots USING btree (procurement_id, created_at DESC) WHERE ((completeness = 'complete'::text) AND (part_key ~~ '%:published'::text))`

#### prc_document_blobs

Owner: Repositories/Procurement/Document; retention: artifacts.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| storage_domain_id | uuid / uuid | NO | uuid NOT NULL |
| content_hash | bytea / bytea | NO | bytea NOT NULL |
| disk | text / text | NO | text NOT NULL |
| object_key | text / text | NO | text NOT NULL |
| size_bytes | bigint / int8 | NO | bigint NOT NULL |
| verified_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| deletion_generation | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| deletion_marked_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_document_blobs_content_hash_check`: `CHECK ((octet_length(content_hash) = 32))`
- `prc_document_blobs_deletion_generation_check`: `CHECK ((deletion_generation >= 0))`
- `prc_document_blobs_disk_check`: `CHECK ((length(disk) > 0))`
- `prc_document_blobs_disk_object_key_key`: `UNIQUE (disk, object_key)`
- `prc_document_blobs_object_key_check`: `CHECK ((length(object_key) > 0))`
- `prc_document_blobs_pkey`: `PRIMARY KEY (id)`
- `prc_document_blobs_size_bytes_check`: `CHECK ((size_bytes >= 0))`
- `prc_document_blobs_storage_domain_id_content_hash_key`: `UNIQUE (storage_domain_id, content_hash)`
- `prc_document_blobs_storage_domain_id_fkey`: `FOREIGN KEY (storage_domain_id) REFERENCES prc_storage_domains(id)`

Indexes:
- `prc_document_blobs_disk_object_key_key`: `CREATE UNIQUE INDEX prc_document_blobs_disk_object_key_key ON public.prc_document_blobs USING btree (disk, object_key)`
- `prc_document_blobs_pkey`: `CREATE UNIQUE INDEX prc_document_blobs_pkey ON public.prc_document_blobs USING btree (id)`
- `prc_document_blobs_storage_domain_id_content_hash_key`: `CREATE UNIQUE INDEX prc_document_blobs_storage_domain_id_content_hash_key ON public.prc_document_blobs USING btree (storage_domain_id, content_hash)`

#### prc_document_representations

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| kind | text / text | NO | text NOT NULL |
| blob_id | uuid / uuid | NO | uuid NOT NULL |
| preparation_recipe_hash | bytea / bytea | NO | bytea NOT NULL |
| content_hash | bytea / bytea | NO | bytea NOT NULL |
| range_key | text / text | NO | text NOT NULL |
| tool_version | text / text | NO | text NOT NULL |
| coverage_manifest | jsonb / jsonb | NO | jsonb NOT NULL |
| coverage_manifest_schema_version | smallint / int2 | NO | smallint NOT NULL |
| storage_path | text / text | NO | text NOT NULL |
| disk | text / text | NO | text NOT NULL |
| size_bytes | bigint / int8 | NO | bigint NOT NULL |
| page_count | integer / int4 | YES | integer |
| token_estimate | integer / int4 | YES | integer |
| mime | text / text | NO | text NOT NULL |
| meta | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| generated_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |

Constraints:
- `prc_document_representations_analysis_unique`: `UNIQUE (id, document_version_id, content_hash)`
- `prc_document_representations_blob_fk`: `FOREIGN KEY (blob_id) REFERENCES prc_document_blobs(id)`
- `prc_document_representations_canonical_tuple_check`: `CHECK (((blob_id IS NOT NULL) AND (preparation_recipe_hash IS NOT NULL) AND (octet_length(preparation_recipe_hash) = 32) AND (content_hash IS NOT NULL) AND (octet_length(content_hash) = 32) AND (range_key IS NOT NULL) AND (length(range_key) > 0) AND (tool_version IS NOT NULL) AND (length(tool_version) > 0) AND (disk IS NOT NULL) AND (length(disk) > 0) AND (size_bytes IS NOT NULL) AND (size_bytes >= 0) AND (coverage_manifest IS NOT NULL) AND (jsonb_typeof(coverage_manifest) = 'object'::text) AND (coverage_manifest_schema_version IS NOT NULL) AND (coverage_manifest_schema_version = 1)))`
- `prc_document_representations_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id) ON DELETE CASCADE`
- `prc_document_representations_pkey`: `PRIMARY KEY (id)`
- `prc_document_representations_recipe_range_unique`: `UNIQUE (document_version_id, preparation_recipe_hash, kind, range_key)`

Indexes:
- `prc_document_representations_analysis_unique`: `CREATE UNIQUE INDEX prc_document_representations_analysis_unique ON public.prc_document_representations USING btree (id, document_version_id, content_hash)`
- `prc_document_representations_document_version_id_index`: `CREATE INDEX prc_document_representations_document_version_id_index ON public.prc_document_representations USING btree (document_version_id)`
- `prc_document_representations_pkey`: `CREATE UNIQUE INDEX prc_document_representations_pkey ON public.prc_document_representations USING btree (id)`
- `prc_document_representations_recipe_range_unique`: `CREATE UNIQUE INDEX prc_document_representations_recipe_range_unique ON public.prc_document_representations USING btree (document_version_id, preparation_recipe_hash, kind, range_key)`

#### prc_document_versions

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| document_id | bigint / int8 | NO | bigint NOT NULL |
| revision_no | bigint / int8 | NO | bigint NOT NULL |
| blob_id | uuid / uuid | NO | uuid NOT NULL |
| etag | text / text | YES | text |
| last_modified | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| first_seen_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |
| normalized_entry_path | text / text | YES | text |
| child_ordinal | bigint / int8 | YES | bigint |
| content_hash | bytea / bytea | NO | bytea NOT NULL |
| size_bytes | bigint / int8 | NO | bigint NOT NULL |
| filename | text / text | NO | text NOT NULL |
| extension | text / text | NO | text NOT NULL |
| mime | text / text | NO | text NOT NULL |
| storage_path | text / text | NO | text NOT NULL |
| disk | text / text | NO | text NOT NULL |
| downloaded_at | timestamp with time zone / timestamptz | NO | timestamp(0) with time zone NOT NULL |
| parent_version_id | bigint / int8 | YES | bigint |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| archive_entry_path | text / text | YES | text |
| analysis_generation | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| current_analysis_run_id | uuid / uuid | YES | uuid |
| published_analysis_run_id | uuid / uuid | YES | uuid |

Constraints:
- `prc_document_versions_analysis_generation_check`: `CHECK ((analysis_generation >= 1))`
- `prc_document_versions_blob_fk`: `FOREIGN KEY (blob_id) REFERENCES prc_document_blobs(id)`
- `prc_document_versions_canonical_tuple_check`: `CHECK (((revision_no IS NOT NULL) AND (revision_no >= 1) AND (blob_id IS NOT NULL) AND (first_seen_at IS NOT NULL) AND (disk IS NOT NULL) AND (length(disk) > 0) AND (octet_length(content_hash) = 32) AND (size_bytes >= 0) AND (((parent_version_id IS NULL) AND (normalized_entry_path IS NULL) AND (child_ordinal IS NULL)) OR ((parent_version_id IS NOT NULL) AND (normalized_entry_path IS NOT NULL) AND (length(normalized_entry_path) > 0) AND (child_ordinal IS NOT NULL) AND (child_ordinal >= 0)))))`
- `prc_document_versions_current_analysis_identity_fk`: `FOREIGN KEY (current_analysis_run_id, id) REFERENCES prc_analysis_runs(id, document_version_id)`
- `prc_document_versions_current_analysis_run_id_fkey`: `FOREIGN KEY (current_analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_document_versions_document_id_foreign`: `FOREIGN KEY (document_id) REFERENCES prc_documents(id)`
- `prc_document_versions_document_id_revision_no_unique`: `UNIQUE (document_id, revision_no)`
- `prc_document_versions_id_document_id_unique`: `UNIQUE (id, document_id)`
- `prc_document_versions_parent_version_id_foreign`: `FOREIGN KEY (parent_version_id) REFERENCES prc_document_versions(id)`
- `prc_document_versions_parent_version_id_normalized_entry_path_u`: `UNIQUE (parent_version_id, normalized_entry_path)`
- `prc_document_versions_pkey`: `PRIMARY KEY (id)`
- `prc_document_versions_published_analysis_identity_fk`: `FOREIGN KEY (published_analysis_run_id, id) REFERENCES prc_analysis_runs(id, document_version_id)`

Indexes:
- `prc_document_versions_content_hash_index`: `CREATE INDEX prc_document_versions_content_hash_index ON public.prc_document_versions USING btree (content_hash)`
- `prc_document_versions_document_id_index`: `CREATE INDEX prc_document_versions_document_id_index ON public.prc_document_versions USING btree (document_id)`
- `prc_document_versions_document_id_revision_no_unique`: `CREATE UNIQUE INDEX prc_document_versions_document_id_revision_no_unique ON public.prc_document_versions USING btree (document_id, revision_no)`
- `prc_document_versions_id_document_id_unique`: `CREATE UNIQUE INDEX prc_document_versions_id_document_id_unique ON public.prc_document_versions USING btree (id, document_id)`
- `prc_document_versions_parent_archive_entry_path_unique`: `CREATE UNIQUE INDEX prc_document_versions_parent_archive_entry_path_unique ON public.prc_document_versions USING btree (parent_version_id, archive_entry_path) WHERE ((parent_version_id IS NOT NULL) AND (archive_entry_path IS NOT NULL))`
- `prc_document_versions_parent_version_id_index`: `CREATE INDEX prc_document_versions_parent_version_id_index ON public.prc_document_versions USING btree (parent_version_id)`
- `prc_document_versions_parent_version_id_normalized_entry_path_u`: `CREATE UNIQUE INDEX prc_document_versions_parent_version_id_normalized_entry_path_u ON public.prc_document_versions USING btree (parent_version_id, normalized_entry_path)`
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
| source_document_key | text / text | YES | text COLLATE pg_catalog."C" |
| source_key_version | integer / int4 | YES | integer |
| access_descriptor | jsonb / jsonb | YES | jsonb |
| access_schema_version | integer / int4 | YES | integer |
| access_descriptor_version | bigint / int8 | YES | bigint |
| reference_observed_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
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
| head_generation | bigint / int8 | NO | bigint DEFAULT '1'::bigint NOT NULL |
| acquisition_cycle_seq | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |
| next_revalidation_due_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| unprocessable_reason | character varying / varchar | YES | character varying(64) |
| current_version_id | bigint / int8 | YES | bigint |

Constraints:
- `prc_documents_acquisition_cycle_seq_check`: `CHECK ((acquisition_cycle_seq >= 0))`
- `prc_documents_current_version_fk`: `FOREIGN KEY (current_version_id, id) REFERENCES prc_document_versions(id, document_id)`
- `prc_documents_head_generation_check`: `CHECK ((head_generation >= 1))`
- `prc_documents_pkey`: `PRIMARY KEY (id)`
- `prc_documents_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_documents_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`
- `prc_documents_reference_check`: `CHECK ((((source_document_key IS NULL) AND (source_key_version IS NULL) AND (access_descriptor IS NULL) AND (access_schema_version IS NULL) AND (access_descriptor_version IS NULL) AND (reference_observed_at IS NULL)) OR ((source_document_key IS NOT NULL) AND ((octet_length(source_document_key) >= 1) AND (octet_length(source_document_key) <= 1024)) AND (source_key_version IS NOT NULL) AND (source_key_version >= 1) AND (access_descriptor IS NOT NULL) AND (jsonb_typeof(access_descriptor) = 'object'::text) AND (access_schema_version IS NOT NULL) AND (access_schema_version = 1) AND (access_descriptor_version IS NOT NULL) AND (access_descriptor_version >= 1) AND (reference_observed_at IS NOT NULL))))`
- `prc_documents_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_documents_pkey`: `CREATE UNIQUE INDEX prc_documents_pkey ON public.prc_documents USING btree (id)`
- `prc_documents_platform_id_index`: `CREATE INDEX prc_documents_platform_id_index ON public.prc_documents USING btree (platform_id)`
- `prc_documents_procurement_external_id_unique`: `CREATE UNIQUE INDEX prc_documents_procurement_external_id_unique ON public.prc_documents USING btree (procurement_id, external_id) WHERE (external_id IS NOT NULL)`
- `prc_documents_procurement_id_index`: `CREATE INDEX prc_documents_procurement_id_index ON public.prc_documents USING btree (procurement_id)`
- `prc_documents_source_key_unique`: `CREATE UNIQUE INDEX prc_documents_source_key_unique ON public.prc_documents USING btree (platform_id, source_document_key) WHERE (source_document_key IS NOT NULL)`
- `prc_documents_unprocessable_reason_index`: `CREATE INDEX prc_documents_unprocessable_reason_index ON public.prc_documents USING btree (unprocessable_reason)`
- `prc_documents_uuid_unique`: `CREATE UNIQUE INDEX prc_documents_uuid_unique ON public.prc_documents USING btree (uuid)`

#### prc_eis_details

Owner: Platforms/Eis (extension writer P2.14); retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| ikz | text / text | YES | text |
| etp_number | text / text | YES | text |
| registry_guid | text / text | YES | text |
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
- `prc_eis_details_ikz_index`: `CREATE INDEX prc_eis_details_ikz_index ON public.prc_eis_details USING btree (ikz)`
- `prc_eis_details_pkey`: `CREATE UNIQUE INDEX prc_eis_details_pkey ON public.prc_eis_details USING btree (procurement_id)`
- `prc_eis_details_registry_guid_index`: `CREATE INDEX prc_eis_details_registry_guid_index ON public.prc_eis_details USING btree (registry_guid)`

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
| unit_multiplier | numeric / numeric | YES | numeric |
| quantity | numeric / numeric | YES | numeric |
| unit_price_base | numeric / numeric | YES | numeric |
| unit_price_curr | numeric / numeric | YES | numeric |
| total_price_base | numeric / numeric | YES | numeric |
| total_price_curr | numeric / numeric | YES | numeric |
| index_code | text / text | YES | text |
| index_value | numeric / numeric | YES | numeric |
| source_position_number | text / text | YES | text |
| work_type_id | bigint / int8 | YES | bigint |
| attributes | jsonb / jsonb | YES | jsonb |
| quality | jsonb / jsonb | YES | jsonb |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| analysis_provenance | jsonb / jsonb | YES | jsonb |
| analysis_provenance_schema_version | smallint / int2 | YES | smallint |

Constraints:
- `prc_estimate_items_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_items_estimate_id_row_index_unique`: `UNIQUE (estimate_id, row_index)`
- `prc_estimate_items_pkey`: `PRIMARY KEY (id)`
- `prc_estimate_items_provenance_pair`: `CHECK (((analysis_provenance IS NULL) = (analysis_provenance_schema_version IS NULL)))`
- `prc_estimate_items_provenance_schema`: `CHECK (((analysis_provenance_schema_version IS NULL) OR (analysis_provenance_schema_version = 1)))`
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
| unit_multiplier | numeric / numeric | YES | numeric |
| quantity | numeric / numeric | YES | numeric |
| price_base | numeric / numeric | YES | numeric |
| price_curr | numeric / numeric | YES | numeric |
| index_code | text / text | YES | text |
| index_value | numeric / numeric | YES | numeric |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| analysis_provenance | jsonb / jsonb | YES | jsonb |
| analysis_provenance_schema_version | smallint / int2 | YES | smallint |

Constraints:
- `prc_estimate_resources_item_id_foreign`: `FOREIGN KEY (item_id) REFERENCES prc_estimate_items(id) ON DELETE CASCADE`
- `prc_estimate_resources_pkey`: `PRIMARY KEY (id)`
- `prc_estimate_resources_provenance_pair`: `CHECK (((analysis_provenance IS NULL) = (analysis_provenance_schema_version IS NULL)))`
- `prc_estimate_resources_provenance_schema`: `CHECK (((analysis_provenance_schema_version IS NULL) OR (analysis_provenance_schema_version = 1)))`

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
| analysis_provenance | jsonb / jsonb | YES | jsonb |
| analysis_provenance_schema_version | smallint / int2 | YES | smallint |

Constraints:
- `prc_estimate_sections_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_sections_parent_id_foreign`: `FOREIGN KEY (parent_id) REFERENCES prc_estimate_sections(id) ON DELETE SET NULL`
- `prc_estimate_sections_pkey`: `PRIMARY KEY (id)`
- `prc_estimate_sections_provenance_pair`: `CHECK (((analysis_provenance IS NULL) = (analysis_provenance_schema_version IS NULL)))`
- `prc_estimate_sections_provenance_schema`: `CHECK (((analysis_provenance_schema_version IS NULL) OR (analysis_provenance_schema_version = 1)))`

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
| amount | numeric / numeric | YES | numeric |
| chapter_number | smallint / int2 | YES | smallint |
| rate | numeric / numeric | YES | numeric |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| analysis_provenance | jsonb / jsonb | YES | jsonb |
| analysis_provenance_schema_version | smallint / int2 | YES | smallint |
| source_node_id | text / text | YES | text |
| source_parent_node_id | text / text | YES | text |

Constraints:
- `prc_estimate_totals_estimate_id_foreign`: `FOREIGN KEY (estimate_id) REFERENCES prc_estimates(id) ON DELETE CASCADE`
- `prc_estimate_totals_parent_id_foreign`: `FOREIGN KEY (parent_id) REFERENCES prc_estimate_totals(id) ON DELETE SET NULL`
- `prc_estimate_totals_pkey`: `PRIMARY KEY (id)`
- `prc_estimate_totals_provenance_pair`: `CHECK (((analysis_provenance IS NULL) = (analysis_provenance_schema_version IS NULL)))`
- `prc_estimate_totals_provenance_schema`: `CHECK (((analysis_provenance_schema_version IS NULL) OR (analysis_provenance_schema_version = 1)))`
- `prc_estimate_totals_source_node_unique`: `UNIQUE (estimate_id, source_node_id)`

Indexes:
- `prc_estimate_totals_estimate_id_index`: `CREATE INDEX prc_estimate_totals_estimate_id_index ON public.prc_estimate_totals USING btree (estimate_id)`
- `prc_estimate_totals_parent_id_index`: `CREATE INDEX prc_estimate_totals_parent_id_index ON public.prc_estimate_totals USING btree (parent_id)`
- `prc_estimate_totals_pkey`: `CREATE UNIQUE INDEX prc_estimate_totals_pkey ON public.prc_estimate_totals USING btree (id)`
- `prc_estimate_totals_source_node_unique`: `CREATE UNIQUE INDEX prc_estimate_totals_source_node_unique ON public.prc_estimate_totals USING btree (estimate_id, source_node_id)`

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
| analysis_id | bigint / int8 | YES | bigint |
| document_version_id | bigint / int8 | NO | bigint NOT NULL |
| procurement_id | bigint / int8 | YES | bigint |
| platform_id | bigint / int8 | YES | bigint |
| ordinal | smallint / int2 | NO | smallint DEFAULT '1'::smallint NOT NULL |
| estimate_number | text / text | YES | text |
| total_amount | numeric / numeric | YES | numeric |
| currency | character / bpchar | YES | character(3) |
| base_year | integer / int4 | YES | integer |
| price_level | text / text | YES | text |
| regulatory_base | text / text | YES | text |
| calc_method | text / text | YES | text |
| labor_hours_workers | numeric / numeric | YES | numeric |
| labor_hours_machinists | numeric / numeric | YES | numeric |
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
| analysis_run_id | uuid / uuid | YES | uuid |
| analysis_provenance | jsonb / jsonb | YES | jsonb |
| analysis_provenance_schema_version | smallint / int2 | YES | smallint |

Constraints:
- `prc_estimates_analysis_id_foreign`: `FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses(id)`
- `prc_estimates_analysis_id_ordinal_unique`: `UNIQUE (analysis_id, ordinal)`
- `prc_estimates_analysis_owner_check`: `CHECK (((analysis_id IS NULL) <> (analysis_run_id IS NULL)))`
- `prc_estimates_analysis_run_id_fkey`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_estimates_analysis_run_ordinal_unique`: `UNIQUE (analysis_run_id, ordinal)`
- `prc_estimates_document_version_id_foreign`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_estimates_pkey`: `PRIMARY KEY (id)`
- `prc_estimates_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_estimates_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`
- `prc_estimates_provenance_pair`: `CHECK (((analysis_provenance IS NULL) = (analysis_provenance_schema_version IS NULL)))`
- `prc_estimates_provenance_schema`: `CHECK (((analysis_provenance_schema_version IS NULL) OR (analysis_provenance_schema_version = 1)))`
- `prc_estimates_uuid_unique`: `UNIQUE (uuid)`
- `prc_estimates_verified_by_foreign`: `FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL`

Indexes:
- `prc_estimates_analysis_id_index`: `CREATE INDEX prc_estimates_analysis_id_index ON public.prc_estimates USING btree (analysis_id)`
- `prc_estimates_analysis_id_ordinal_unique`: `CREATE UNIQUE INDEX prc_estimates_analysis_id_ordinal_unique ON public.prc_estimates USING btree (analysis_id, ordinal)`
- `prc_estimates_analysis_run_ordinal_unique`: `CREATE UNIQUE INDEX prc_estimates_analysis_run_ordinal_unique ON public.prc_estimates USING btree (analysis_run_id, ordinal)`
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

#### prc_network_authorities

Owner: Services/Procurement/Proxyma; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | text / text | NO | text NOT NULL |
| authority | text / text | NO | text DEFAULT 'legacy'::text NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| cutover_id | uuid / uuid | YES | uuid |
| paused | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| legacy_frozen | boolean / bool | NO | boolean DEFAULT false NOT NULL |

Constraints:
- `prc_network_authorities_cutover_id_foreign`: `FOREIGN KEY (cutover_id) REFERENCES prc_network_cutover_journals(id)`
- `prc_network_authorities_pkey`: `PRIMARY KEY (id)`
- `prc_network_authorities_valid`: `CHECK (((id = 'network'::text) AND (generation > 0) AND (authority = ANY (ARRAY['legacy'::text, 'proxyma'::text])) AND ((authority = 'legacy'::text) OR (cutover_id IS NOT NULL))))`

Indexes:
- `prc_network_authorities_pkey`: `CREATE UNIQUE INDEX prc_network_authorities_pkey ON public.prc_network_authorities USING btree (id)`

#### prc_network_budgets

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| scope_key | text / text | NO | text NOT NULL |
| emission_interval_us | bigint / int8 | NO | bigint NOT NULL |
| burst | smallint / int2 | NO | smallint DEFAULT 1 NOT NULL |
| max_inflight | smallint / int2 | NO | smallint NOT NULL |
| tat | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |
| send_tat | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |
| not_before | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |
| policy_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_network_budgets_burst_check`: `CHECK ((burst >= 1))`
- `prc_network_budgets_emission_interval_us_check`: `CHECK ((emission_interval_us > 0))`
- `prc_network_budgets_max_inflight_check`: `CHECK ((max_inflight >= 1))`
- `prc_network_budgets_pkey`: `PRIMARY KEY (id)`
- `prc_network_budgets_policy_version_check`: `CHECK ((policy_version >= 1))`
- `prc_network_budgets_scope_key_key`: `UNIQUE (scope_key)`

Indexes:
- `prc_network_budgets_pkey`: `CREATE UNIQUE INDEX prc_network_budgets_pkey ON public.prc_network_budgets USING btree (id)`
- `prc_network_budgets_scope_key_key`: `CREATE UNIQUE INDEX prc_network_budgets_scope_key_key ON public.prc_network_budgets USING btree (scope_key)`

#### prc_network_cutover_journals

Owner: Services/Procurement/Proxyma; retention: journal.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| source_revision | text / text | NO | text NOT NULL |
| target_revision | text / text | NO | text NOT NULL |
| phase | text / text | NO | text NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| first_authorized_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| checkpoint | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |

Constraints:
- `prc_network_cutover_journals_pkey`: `PRIMARY KEY (id)`
- `prc_network_cutover_journals_valid`: `CHECK (((generation > 0) AND (phase = ANY (ARRAY['prepared'::text, 'draining'::text, 'verified'::text, 'switched'::text, 'accepted'::text]))))`
- `prc_pxm_journal_identity`: `UNIQUE (source_revision, target_revision, generation)`

Indexes:
- `prc_network_cutover_journals_pkey`: `CREATE UNIQUE INDEX prc_network_cutover_journals_pkey ON public.prc_network_cutover_journals USING btree (id)`
- `prc_pxm_journal_identity`: `CREATE UNIQUE INDEX prc_pxm_journal_identity ON public.prc_network_cutover_journals USING btree (source_revision, target_revision, generation)`

#### prc_network_demands

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| physical_request_id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | YES | uuid |
| attempt_id | uuid / uuid | YES | uuid |
| work_generation | bigint / int8 | YES | bigint |
| operation_id | uuid / uuid | YES | uuid |
| operation_generation | bigint / int8 | YES | bigint |
| origin_budget_id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| traffic_class | text / text | NO | text NOT NULL |
| purpose | text / text | NO | text NOT NULL |
| ticket_seq | bigint / int8 | NO | bigint NOT NULL |
| registered_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| renewed_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| expires_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| operation_deadline | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| state | text / text | NO | text NOT NULL |

Constraints:
- `prc_network_demands_attempt_id_work_id_fkey`: `FOREIGN KEY (attempt_id, work_id) REFERENCES prc_process_attempts(id, work_id)`
- `prc_network_demands_check`: `CHECK ((((work_id IS NOT NULL) AND (attempt_id IS NOT NULL) AND (work_generation IS NOT NULL) AND (work_generation >= 1) AND (operation_id IS NULL) AND (operation_generation IS NULL)) OR ((work_id IS NULL) AND (attempt_id IS NULL) AND (work_generation IS NULL) AND (operation_id IS NOT NULL) AND (operation_generation IS NOT NULL) AND (operation_generation >= 1))))`
- `prc_network_demands_check1`: `CHECK ((expires_at <= operation_deadline))`
- `prc_network_demands_operation_id_fkey`: `FOREIGN KEY (operation_id) REFERENCES prc_outbound_operations(id)`
- `prc_network_demands_origin_budget_id_fkey`: `FOREIGN KEY (origin_budget_id) REFERENCES prc_network_budgets(id)`
- `prc_network_demands_physical_request_id_key`: `UNIQUE (physical_request_id)`
- `prc_network_demands_pkey`: `PRIMARY KEY (id)`
- `prc_network_demands_purpose_check`: `CHECK ((purpose = ANY (ARRAY['ordinary'::text, 'probe'::text, 'diagnostic'::text])))`
- `prc_network_demands_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `prc_network_demands_state_check`: `CHECK ((state = ANY (ARRAY['queued'::text, 'granted'::text, 'cancelled'::text, 'expired'::text])))`
- `prc_network_demands_target_id_fkey`: `FOREIGN KEY (target_id) REFERENCES prc_source_targets(id)`
- `prc_network_demands_ticket_seq_key`: `UNIQUE (ticket_seq)`
- `prc_network_demands_traffic_class_check`: `CHECK ((traffic_class = ANY (ARRAY['fresh_listing'::text, 'status_refresh'::text, 'detail'::text, 'archive_listing'::text, 'download'::text, 'reference_sync'::text, 'diagnostic'::text, 'provider'::text])))`
- `prc_network_demands_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_network_demands_attempt_active`: `CREATE UNIQUE INDEX prc_network_demands_attempt_active ON public.prc_network_demands USING btree (attempt_id) WHERE ((state = 'queued'::text) AND (attempt_id IS NOT NULL))`
- `prc_network_demands_class`: `CREATE INDEX prc_network_demands_class ON public.prc_network_demands USING btree (origin_budget_id, traffic_class, id)`
- `prc_network_demands_fifo`: `CREATE INDEX prc_network_demands_fifo ON public.prc_network_demands USING btree (origin_budget_id, ticket_seq) WHERE (state = 'queued'::text)`
- `prc_network_demands_operation_active`: `CREATE UNIQUE INDEX prc_network_demands_operation_active ON public.prc_network_demands USING btree (operation_id) WHERE ((state = 'queued'::text) AND (operation_id IS NOT NULL))`
- `prc_network_demands_physical_request_id_key`: `CREATE UNIQUE INDEX prc_network_demands_physical_request_id_key ON public.prc_network_demands USING btree (physical_request_id)`
- `prc_network_demands_pkey`: `CREATE UNIQUE INDEX prc_network_demands_pkey ON public.prc_network_demands USING btree (id)`
- `prc_network_demands_ticket_seq_key`: `CREATE UNIQUE INDEX prc_network_demands_ticket_seq_key ON public.prc_network_demands USING btree (ticket_seq)`

#### prc_network_permit_scopes

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| permit_id | uuid / uuid | NO | uuid NOT NULL |
| budget_id | uuid / uuid | NO | uuid NOT NULL |

Constraints:
- `prc_network_permit_scopes_budget_id_fkey`: `FOREIGN KEY (budget_id) REFERENCES prc_network_budgets(id)`
- `prc_network_permit_scopes_permit_id_fkey`: `FOREIGN KEY (permit_id) REFERENCES prc_network_permits(id) ON DELETE CASCADE`
- `prc_network_permit_scopes_pkey`: `PRIMARY KEY (permit_id, budget_id)`

Indexes:
- `prc_network_permit_scopes_budget`: `CREATE INDEX prc_network_permit_scopes_budget ON public.prc_network_permit_scopes USING btree (budget_id)`
- `prc_network_permit_scopes_pkey`: `CREATE UNIQUE INDEX prc_network_permit_scopes_pkey ON public.prc_network_permit_scopes USING btree (permit_id, budget_id)`

#### prc_network_permits

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| demand_id | uuid / uuid | NO | uuid NOT NULL |
| slot_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| expires_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| authorized_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| state | text / text | NO | text NOT NULL |
| policy_versions | jsonb / jsonb | NO | jsonb NOT NULL |
| policy_schema_version | smallint / int2 | NO | smallint NOT NULL |
| health_epochs | jsonb / jsonb | NO | jsonb NOT NULL |
| health_epochs_schema_version | smallint / int2 | NO | smallint NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_network_permits_check`: `CHECK ((expires_at > slot_at))`
- `prc_network_permits_check1`: `CHECK (((state <> 'reserved'::text) OR (authorized_at IS NULL)))`
- `prc_network_permits_check2`: `CHECK (((state <> 'sent'::text) OR (authorized_at IS NOT NULL)))`
- `prc_network_permits_demand_id_fkey`: `FOREIGN KEY (demand_id) REFERENCES prc_network_demands(id)`
- `prc_network_permits_demand_id_key`: `UNIQUE (demand_id)`
- `prc_network_permits_health_epochs_schema_version_check`: `CHECK ((health_epochs_schema_version >= 1))`
- `prc_network_permits_pkey`: `PRIMARY KEY (id)`
- `prc_network_permits_policy_schema_version_check`: `CHECK ((policy_schema_version >= 1))`
- `prc_network_permits_state_check`: `CHECK ((state = ANY (ARRAY['reserved'::text, 'sent'::text, 'released'::text, 'expired'::text])))`

Indexes:
- `prc_network_permits_active`: `CREATE INDEX prc_network_permits_active ON public.prc_network_permits USING btree (expires_at) WHERE (state = ANY (ARRAY['reserved'::text, 'sent'::text]))`
- `prc_network_permits_authorized`: `CREATE INDEX prc_network_permits_authorized ON public.prc_network_permits USING btree (authorized_at) WHERE (authorized_at IS NOT NULL)`
- `prc_network_permits_demand_id_key`: `CREATE UNIQUE INDEX prc_network_permits_demand_id_key ON public.prc_network_permits USING btree (demand_id)`
- `prc_network_permits_pkey`: `CREATE UNIQUE INDEX prc_network_permits_pkey ON public.prc_network_permits USING btree (id)`

#### prc_network_route_profile_members

Owner: Repositories/Procurement/Network/RouteRepository; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |

Constraints:
- `prc_network_route_profile_members_pkey`: `PRIMARY KEY (profile_id, route_id)`
- `prc_network_route_profile_members_profile_id_fkey`: `FOREIGN KEY (profile_id) REFERENCES prc_network_route_profiles(id)`
- `prc_network_route_profile_members_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`

Indexes:
- `prc_network_route_profile_members_pkey`: `CREATE UNIQUE INDEX prc_network_route_profile_members_pkey ON public.prc_network_route_profile_members USING btree (profile_id, route_id)`

#### prc_network_route_profiles

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| profile_key | text / text | NO | text NOT NULL |
| policy_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_network_route_profiles_pkey`: `PRIMARY KEY (id)`
- `prc_network_route_profiles_policy_version_check`: `CHECK ((policy_version >= 1))`
- `prc_network_route_profiles_profile_key_key`: `UNIQUE (profile_key)`

Indexes:
- `prc_network_route_profiles_pkey`: `CREATE UNIQUE INDEX prc_network_route_profiles_pkey ON public.prc_network_route_profiles USING btree (id)`
- `prc_network_route_profiles_profile_key_key`: `CREATE UNIQUE INDEX prc_network_route_profiles_profile_key_key ON public.prc_network_route_profiles USING btree (profile_key)`

#### prc_network_routes

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| route_key | text / text | NO | text NOT NULL |
| kind | text / text | NO | text NOT NULL |
| egress_id | text / text | NO | text NOT NULL |
| independence_group | text / text | YES | text |
| enabled | boolean / bool | NO | boolean NOT NULL |
| disabled_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| disabled_reason | text / text | YES | text |
| secret_ref | text / text | YES | text |
| credential_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_network_routes_credential_version_check`: `CHECK ((credential_version >= 1))`
- `prc_network_routes_kind_check`: `CHECK ((kind = ANY (ARRAY['direct'::text, 'proxy'::text])))`
- `prc_network_routes_pkey`: `PRIMARY KEY (id)`
- `prc_network_routes_route_key_key`: `UNIQUE (route_key)`

Indexes:
- `prc_network_routes_pkey`: `CREATE UNIQUE INDEX prc_network_routes_pkey ON public.prc_network_routes USING btree (id)`
- `prc_network_routes_route_key_key`: `CREATE UNIQUE INDEX prc_network_routes_route_key_key ON public.prc_network_routes USING btree (route_key)`

#### prc_observation_applications

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| observation_id | uuid / uuid | NO | uuid NOT NULL |
| projection_key | text / text | NO | text NOT NULL |
| reducer_version | bigint / int8 | NO | bigint NOT NULL |
| applied_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_observation_applications_observation_id_fkey`: `FOREIGN KEY (observation_id) REFERENCES prc_observations(observation_id)`
- `prc_observation_applications_pkey`: `PRIMARY KEY (observation_id, projection_key)`
- `prc_observation_applications_reducer_version_check`: `CHECK ((reducer_version >= 1))`

Indexes:
- `prc_observation_applications_pkey`: `CREATE UNIQUE INDEX prc_observation_applications_pkey ON public.prc_observation_applications USING btree (observation_id, projection_key)`

#### prc_observations

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| observation_id | uuid / uuid | NO | uuid NOT NULL |
| request_id | uuid / uuid | YES | uuid |
| attempt_id | uuid / uuid | YES | uuid |
| kind | text / text | NO | text NOT NULL |
| reason_code | text / text | NO | text NOT NULL |
| input_hash | bytea / bytea | NO | bytea NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| epochs | jsonb / jsonb | NO | jsonb NOT NULL |
| epochs_schema_version | smallint / int2 | NO | smallint NOT NULL |
| stale | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| details | jsonb / jsonb | YES | jsonb |
| details_schema_version | smallint / int2 | YES | smallint |
| occurred_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_observations_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_observations_details_schema_check`: `CHECK (((details IS NULL) = (details_schema_version IS NULL)))`
- `prc_observations_details_schema_version_check`: `CHECK ((details_schema_version > 0))`
- `prc_observations_epochs_schema_version_check`: `CHECK ((epochs_schema_version > 0))`
- `prc_observations_generation_check`: `CHECK ((generation >= 0))`
- `prc_observations_input_hash_check`: `CHECK ((octet_length(input_hash) = 32))`
- `prc_observations_pkey`: `PRIMARY KEY (observation_id)`
- `prc_observations_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES prc_request_attempts(request_id)`

Indexes:
- `prc_observations_attempt_idx`: `CREATE INDEX prc_observations_attempt_idx ON public.prc_observations USING btree (attempt_id)`
- `prc_observations_occurred_idx`: `CREATE INDEX prc_observations_occurred_idx ON public.prc_observations USING btree (occurred_at)`
- `prc_observations_pkey`: `CREATE UNIQUE INDEX prc_observations_pkey ON public.prc_observations USING btree (observation_id)`

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

#### prc_operation_intents

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| intent_key | bytea / bytea | NO | bytea NOT NULL |
| stage | text / text | NO | text NOT NULL |
| subject_kind | text / text | NO | text NOT NULL |
| subject_id | text / text | NO | text NOT NULL |
| semantic_policy_hash | bytea / bytea | NO | bytea NOT NULL |
| access_policy_hash | bytea / bytea | NO | bytea NOT NULL |
| cycle_seq | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| expected_head_generation | bigint / int8 | NO | bigint NOT NULL |
| state | text / text | NO | text NOT NULL |
| pending_reason | text / text | YES | text |
| requested_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| next_due_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_operation_intents_access_policy_hash_check`: `CHECK ((octet_length(access_policy_hash) = 32))`
- `prc_operation_intents_check`: `CHECK (((pending_reason IS NULL) OR ((state = 'pending'::text) AND (pending_reason = 'handler_not_activated'::text))))`
- `prc_operation_intents_cycle_seq_check`: `CHECK ((cycle_seq >= 1))`
- `prc_operation_intents_expected_head_generation_check`: `CHECK ((expected_head_generation >= 1))`
- `prc_operation_intents_intent_key_check`: `CHECK ((octet_length(intent_key) = 32))`
- `prc_operation_intents_intent_key_unique`: `UNIQUE (intent_key)`
- `prc_operation_intents_pkey`: `PRIMARY KEY (id)`
- `prc_operation_intents_semantic_policy_hash_check`: `CHECK ((octet_length(semantic_policy_hash) = 32))`
- `prc_operation_intents_stage_check`: `CHECK ((stage = ANY (ARRAY['listing'::text, 'detail'::text, 'status_refresh'::text, 'download'::text, 'prepare'::text, 'screening'::text, 'extraction'::text, 'publication'::text])))`
- `prc_operation_intents_state_check`: `CHECK ((state = ANY (ARRAY['pending'::text, 'active'::text, 'terminal'::text])))`

Indexes:
- `prc_operation_intents_intent_key_unique`: `CREATE UNIQUE INDEX prc_operation_intents_intent_key_unique ON public.prc_operation_intents USING btree (intent_key)`
- `prc_operation_intents_one_active_unique`: `CREATE UNIQUE INDEX prc_operation_intents_one_active_unique ON public.prc_operation_intents USING btree (stage, subject_kind, subject_id) WHERE (state = 'active'::text)`
- `prc_operation_intents_pkey`: `CREATE UNIQUE INDEX prc_operation_intents_pkey ON public.prc_operation_intents USING btree (id)`
- `prc_operation_intents_state_due_idx`: `CREATE INDEX prc_operation_intents_state_due_idx ON public.prc_operation_intents USING btree (state, next_due_at)`

#### prc_outbound_operation_controls

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| control_scope_id | uuid / uuid | NO | uuid NOT NULL |
| control_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_outbound_operation_controls_control_scope_id_fkey`: `FOREIGN KEY (control_scope_id) REFERENCES prc_control_scopes(id)`
- `prc_outbound_operation_controls_operation_id_fkey`: `FOREIGN KEY (operation_id) REFERENCES prc_outbound_operations(id)`
- `prc_outbound_operation_controls_pkey`: `PRIMARY KEY (operation_id, control_scope_id)`

Indexes:
- `prc_outbound_operation_controls_pkey`: `CREATE UNIQUE INDEX prc_outbound_operation_controls_pkey ON public.prc_outbound_operation_controls USING btree (operation_id, control_scope_id)`

#### prc_outbound_operations

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| operation_key | uuid / uuid | NO | uuid NOT NULL |
| kind | text / text | NO | text NOT NULL |
| platform_id | bigint / int8 | YES | bigint |
| provider_key | text / text | YES | text |
| adapter_health_id | uuid / uuid | YES | uuid |
| adapter_health_epoch | bigint / int8 | YES | bigint |
| parent_attempt_id | uuid / uuid | YES | uuid |
| caller_code | text / text | NO | text NOT NULL |
| state | text / text | NO | text NOT NULL |
| owner_token | uuid / uuid | NO | uuid NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| lease_until | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| deadline_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| policy_hash | bytea / bytea | NO | bytea NOT NULL |

Constraints:
- `prc_outbound_operations_adapter_health_epoch_check`: `CHECK (((adapter_health_epoch IS NULL) OR (adapter_health_epoch >= 1)))`
- `prc_outbound_operations_adapter_health_id_fkey`: `FOREIGN KEY (adapter_health_id) REFERENCES prc_adapter_health(id)`
- `prc_outbound_operations_check`: `CHECK (((platform_id IS NULL) OR (provider_key IS NULL)))`
- `prc_outbound_operations_check1`: `CHECK (((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL)))`
- `prc_outbound_operations_check2`: `CHECK (((parent_attempt_id IS NULL) OR (kind = 'probe'::text)))`
- `prc_outbound_operations_check3`: `CHECK ((lease_until <= deadline_at))`
- `prc_outbound_operations_check4`: `CHECK (((kind = 'probe'::text) = (probe_token IS NOT NULL)))`
- `prc_outbound_operations_check5`: `CHECK (((state = 'open'::text) = (finished_at IS NULL)))`
- `prc_outbound_operations_generation_check`: `CHECK ((generation >= 1))`
- `prc_outbound_operations_kind_check`: `CHECK ((kind = ANY (ARRAY['legacy_bridge'::text, 'diagnostic'::text, 'probe'::text])))`
- `prc_outbound_operations_operation_key_key`: `UNIQUE (operation_key)`
- `prc_outbound_operations_parent_attempt_id_fkey`: `FOREIGN KEY (parent_attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_outbound_operations_pkey`: `PRIMARY KEY (id)`
- `prc_outbound_operations_platform_id_fkey`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_outbound_operations_policy_hash_check`: `CHECK ((octet_length(policy_hash) = 32))`
- `prc_outbound_operations_provider_key_check`: `CHECK (((provider_key IS NULL) OR (provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$'::text)))`
- `prc_outbound_operations_state_check`: `CHECK ((state = ANY (ARRAY['open'::text, 'closed'::text, 'expired'::text])))`

Indexes:
- `prc_outbound_operations_adapter_live`: `CREATE INDEX prc_outbound_operations_adapter_live ON public.prc_outbound_operations USING btree (adapter_health_id, adapter_health_epoch, lease_until) WHERE ((state = 'open'::text) AND (adapter_health_id IS NOT NULL))`
- `prc_outbound_operations_operation_key_key`: `CREATE UNIQUE INDEX prc_outbound_operations_operation_key_key ON public.prc_outbound_operations USING btree (operation_key)`
- `prc_outbound_operations_pkey`: `CREATE UNIQUE INDEX prc_outbound_operations_pkey ON public.prc_outbound_operations USING btree (id)`
- `prc_outbound_operations_reap`: `CREATE INDEX prc_outbound_operations_reap ON public.prc_outbound_operations USING btree (lease_until) WHERE (state = 'open'::text)`

#### prc_parsing_rule_events

Owner: Procurement; retention: journal.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| rule_set_id | bigint / int8 | NO | bigint NOT NULL |
| rule_version_id | bigint / int8 | YES | bigint |
| event_type | character varying / varchar | NO | character varying(32) NOT NULL |
| actor_type | character varying / varchar | NO | character varying(32) NOT NULL |
| actor_id | bigint / int8 | YES | bigint |
| reason | text / text | NO | text NOT NULL |
| metadata | jsonb / jsonb | YES | jsonb |
| occurred_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |

Constraints:
- `prc_parsing_rule_events_actor_id_fkey`: `FOREIGN KEY (actor_id) REFERENCES users(id)`
- `prc_parsing_rule_events_actor_type_check`: `CHECK (((actor_type)::text = ANY ((ARRAY['user'::character varying, 'system'::character varying, 'cli'::character varying, 'seeder'::character varying, 'auto_heal'::character varying])::text[])))`
- `prc_parsing_rule_events_event_type_check`: `CHECK (((event_type)::text = ANY ((ARRAY['created'::character varying, 'validated'::character varying, 'validation_failed'::character varying, 'activated'::character varying, 'rolled_back'::character varying, 'archived'::character varying, 'drift_detected'::character varying, 'candidate_proposed'::character varying, 'candidate_verified'::character varying])::text[])))`
- `prc_parsing_rule_events_pkey`: `PRIMARY KEY (id)`
- `prc_parsing_rule_events_rule_set_id_fkey`: `FOREIGN KEY (rule_set_id) REFERENCES prc_parsing_rule_sets(id)`
- `prc_parsing_rule_events_rule_version_id_fkey`: `FOREIGN KEY (rule_version_id) REFERENCES prc_parsing_rule_versions(id)`

Indexes:
- `prc_parsing_rule_events_occurred_at_idx`: `CREATE INDEX prc_parsing_rule_events_occurred_at_idx ON public.prc_parsing_rule_events USING btree (rule_set_id, occurred_at DESC)`
- `prc_parsing_rule_events_pkey`: `CREATE UNIQUE INDEX prc_parsing_rule_events_pkey ON public.prc_parsing_rule_events USING btree (id)`
- `prc_parsing_rule_events_type_occurred_at_idx`: `CREATE INDEX prc_parsing_rule_events_type_occurred_at_idx ON public.prc_parsing_rule_events USING btree (rule_set_id, event_type, occurred_at DESC)`

#### prc_parsing_rule_sets

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| stage | character varying / varchar | NO | character varying(32) NOT NULL |
| registry_code | character varying / varchar | YES | character varying(64) |
| scope_key | character varying / varchar | NO | character varying(64) DEFAULT 'default'::character varying NOT NULL |
| active_version_id | bigint / int8 | YES | bigint |
| current_version_number | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| auto_heal_mode | character varying / varchar | NO | character varying(32) DEFAULT 'propose'::character varying NOT NULL |
| description | text / text | YES | text |
| is_enabled | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |

Constraints:
- `prc_parsing_rule_sets_active_version_fk`: `FOREIGN KEY (id, active_version_id) REFERENCES prc_parsing_rule_versions(rule_set_id, id)`
- `prc_parsing_rule_sets_auto_heal_mode_check`: `CHECK (((auto_heal_mode)::text = ANY ((ARRAY['disabled'::character varying, 'propose'::character varying, 'auto_promote_fallback'::character varying])::text[])))`
- `prc_parsing_rule_sets_current_version_number_check`: `CHECK ((current_version_number >= 0))`
- `prc_parsing_rule_sets_pkey`: `PRIMARY KEY (id)`
- `prc_parsing_rule_sets_platform_id_fkey`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_parsing_rule_sets_stage_check`: `CHECK (((stage)::text = ANY ((ARRAY['listing'::character varying, 'detail'::character varying, 'status_refresh'::character varying])::text[])))`
- `prc_parsing_rule_sets_uuid_key`: `UNIQUE (uuid)`

Indexes:
- `prc_parsing_rule_sets_active_lookup_idx`: `CREATE INDEX prc_parsing_rule_sets_active_lookup_idx ON public.prc_parsing_rule_sets USING btree (platform_id, stage, is_enabled)`
- `prc_parsing_rule_sets_pkey`: `CREATE UNIQUE INDEX prc_parsing_rule_sets_pkey ON public.prc_parsing_rule_sets USING btree (id)`
- `prc_parsing_rule_sets_scope_unique`: `CREATE UNIQUE INDEX prc_parsing_rule_sets_scope_unique ON public.prc_parsing_rule_sets USING btree (platform_id, stage, COALESCE(registry_code, ''::character varying), scope_key)`
- `prc_parsing_rule_sets_uuid_key`: `CREATE UNIQUE INDEX prc_parsing_rule_sets_uuid_key ON public.prc_parsing_rule_sets USING btree (uuid)`

#### prc_parsing_rule_versions

Owner: Procurement; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | bigint / int8 | NO | bigint NOT NULL |
| uuid | uuid / uuid | NO | uuid NOT NULL |
| rule_set_id | bigint / int8 | NO | bigint NOT NULL |
| version | integer / int4 | NO | integer NOT NULL |
| schema_id | character varying / varchar | NO | character varying(128) NOT NULL |
| schema_version | smallint / int2 | NO | smallint DEFAULT 1 NOT NULL |
| status | character varying / varchar | NO | character varying(32) NOT NULL |
| rules_json | jsonb / jsonb | NO | jsonb NOT NULL |
| rules_hash | bytea / bytea | NO | bytea NOT NULL |
| source | character varying / varchar | NO | character varying(32) DEFAULT 'manual'::character varying NOT NULL |
| change_summary | text / text | NO | text NOT NULL |
| validation_report | jsonb / jsonb | YES | jsonb |
| parent_version_id | bigint / int8 | YES | bigint |
| created_by | bigint / int8 | YES | bigint |
| activated_by | bigint / int8 | YES | bigint |
| activated_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| deactivated_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| created_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |

Constraints:
- `prc_parsing_rule_versions_activated_by_fkey`: `FOREIGN KEY (activated_by) REFERENCES users(id)`
- `prc_parsing_rule_versions_created_by_fkey`: `FOREIGN KEY (created_by) REFERENCES users(id)`
- `prc_parsing_rule_versions_lifecycle_check`: `CHECK (((((status)::text = 'active'::text) AND (activated_at IS NOT NULL) AND (deactivated_at IS NULL)) OR (((status)::text = 'superseded'::text) AND (activated_at IS NOT NULL) AND (deactivated_at IS NOT NULL) AND (deactivated_at >= activated_at)) OR (((status)::text = ANY ((ARRAY['draft'::character varying, 'validated'::character varying, 'rejected'::character varying])::text[])) AND (activated_at IS NULL) AND (deactivated_at IS NULL))))`
- `prc_parsing_rule_versions_parent_version_id_fkey`: `FOREIGN KEY (parent_version_id) REFERENCES prc_parsing_rule_versions(id)`
- `prc_parsing_rule_versions_pkey`: `PRIMARY KEY (id)`
- `prc_parsing_rule_versions_rule_set_id_fkey`: `FOREIGN KEY (rule_set_id) REFERENCES prc_parsing_rule_sets(id)`
- `prc_parsing_rule_versions_rule_set_id_id_key`: `UNIQUE (rule_set_id, id)`
- `prc_parsing_rule_versions_rule_set_id_version_key`: `UNIQUE (rule_set_id, version)`
- `prc_parsing_rule_versions_rules_hash_check`: `CHECK ((octet_length(rules_hash) = 32))`
- `prc_parsing_rule_versions_rules_json_check`: `CHECK ((jsonb_typeof(rules_json) = 'object'::text))`
- `prc_parsing_rule_versions_schema_version_check`: `CHECK ((schema_version >= 1))`
- `prc_parsing_rule_versions_source_check`: `CHECK (((source)::text = ANY ((ARRAY['seed'::character varying, 'manual'::character varying, 'import'::character varying, 'auto_heal'::character varying, 'rollback'::character varying])::text[])))`
- `prc_parsing_rule_versions_status_check`: `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'validated'::character varying, 'active'::character varying, 'superseded'::character varying, 'rejected'::character varying])::text[])))`
- `prc_parsing_rule_versions_uuid_key`: `UNIQUE (uuid)`
- `prc_parsing_rule_versions_version_check`: `CHECK ((version >= 1))`

Indexes:
- `prc_parsing_rule_versions_created_at_idx`: `CREATE INDEX prc_parsing_rule_versions_created_at_idx ON public.prc_parsing_rule_versions USING btree (rule_set_id, created_at DESC)`
- `prc_parsing_rule_versions_one_active_idx`: `CREATE UNIQUE INDEX prc_parsing_rule_versions_one_active_idx ON public.prc_parsing_rule_versions USING btree (rule_set_id) WHERE ((status)::text = 'active'::text)`
- `prc_parsing_rule_versions_pkey`: `CREATE UNIQUE INDEX prc_parsing_rule_versions_pkey ON public.prc_parsing_rule_versions USING btree (id)`
- `prc_parsing_rule_versions_rule_set_id_id_key`: `CREATE UNIQUE INDEX prc_parsing_rule_versions_rule_set_id_id_key ON public.prc_parsing_rule_versions USING btree (rule_set_id, id)`
- `prc_parsing_rule_versions_rule_set_id_version_key`: `CREATE UNIQUE INDEX prc_parsing_rule_versions_rule_set_id_version_key ON public.prc_parsing_rule_versions USING btree (rule_set_id, version)`
- `prc_parsing_rule_versions_status_idx`: `CREATE INDEX prc_parsing_rule_versions_status_idx ON public.prc_parsing_rule_versions USING btree (rule_set_id, status)`
- `prc_parsing_rule_versions_uuid_key`: `CREATE UNIQUE INDEX prc_parsing_rule_versions_uuid_key ON public.prc_parsing_rule_versions USING btree (uuid)`

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

#### prc_process_attempts

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | NO | uuid NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| owner_token_fingerprint | bytea / bytea | NO | bytea NOT NULL |
| handler_version | text / text | NO | text NOT NULL |
| fault_signature | bytea / bytea | YES | bytea |
| fault_policy_checked_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| started_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_heartbeat_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_checkpoint_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| terminal_kind | text / text | YES | text |
| worker_host | text / text | NO | text NOT NULL |
| worker_pid | integer / int4 | NO | integer NOT NULL |
| adapter_health_id | uuid / uuid | YES | uuid |
| adapter_health_epoch | bigint / int8 | YES | bigint |

Constraints:
- `prc_attempt_work_pair`: `UNIQUE (id, work_id)`
- `prc_process_attempts_adapter_health_epoch_check`: `CHECK (((adapter_health_epoch IS NULL) OR (adapter_health_epoch >= 1)))`
- `prc_process_attempts_adapter_health_id_fkey`: `FOREIGN KEY (adapter_health_id) REFERENCES prc_adapter_health(id)`
- `prc_process_attempts_adapter_pair`: `CHECK (((adapter_health_id IS NULL) = (adapter_health_epoch IS NULL)))`
- `prc_process_attempts_fault_signature_check`: `CHECK (((fault_signature IS NULL) OR (octet_length(fault_signature) = 32)))`
- `prc_process_attempts_generation_check`: `CHECK ((generation >= 1))`
- `prc_process_attempts_owner_token_fingerprint_check`: `CHECK ((octet_length(owner_token_fingerprint) = 32))`
- `prc_process_attempts_pkey`: `PRIMARY KEY (id)`
- `prc_process_attempts_terminal_kind_check`: `CHECK ((terminal_kind = ANY (ARRAY['succeeded'::text, 'partial'::text, 'deferred'::text, 'blocked'::text, 'failed_item'::text, 'interrupted'::text, 'cancelled'::text, 'superseded'::text, 'lost_ownership'::text])))`
- `prc_process_attempts_work_generation_unique`: `UNIQUE (work_id, generation)`
- `prc_process_attempts_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_attempt_work_pair`: `CREATE UNIQUE INDEX prc_attempt_work_pair ON public.prc_process_attempts USING btree (id, work_id)`
- `prc_process_attempts_adapter_live`: `CREATE INDEX prc_process_attempts_adapter_live ON public.prc_process_attempts USING btree (adapter_health_id, adapter_health_epoch) WHERE ((finished_at IS NULL) AND (adapter_health_id IS NOT NULL))`
- `prc_process_attempts_fault_idx`: `CREATE INDEX prc_process_attempts_fault_idx ON public.prc_process_attempts USING btree (handler_version, fault_signature, finished_at) WHERE (fault_signature IS NOT NULL)`
- `prc_process_attempts_fault_policy_pending_idx`: `CREATE INDEX prc_process_attempts_fault_policy_pending_idx ON public.prc_process_attempts USING btree (finished_at, id) WHERE ((fault_signature IS NOT NULL) AND (fault_policy_checked_at IS NULL))`
- `prc_process_attempts_pkey`: `CREATE UNIQUE INDEX prc_process_attempts_pkey ON public.prc_process_attempts USING btree (id)`
- `prc_process_attempts_work_fault_idx`: `CREATE INDEX prc_process_attempts_work_fault_idx ON public.prc_process_attempts USING btree (work_id, handler_version) WHERE ((fault_signature IS NOT NULL) AND (finished_at IS NOT NULL))`
- `prc_process_attempts_work_generation_unique`: `CREATE UNIQUE INDEX prc_process_attempts_work_generation_unique ON public.prc_process_attempts USING btree (work_id, generation)`

#### prc_process_daemon_heartbeats

Owner: Repositories/Procurement/Process; retention: technical.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| instance_id | uuid / uuid | NO | uuid NOT NULL |
| daemon | text / text | NO | text NOT NULL |
| host | text / text | NO | text NOT NULL |
| pid | integer / int4 | NO | integer NOT NULL |
| started_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| last_tick_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| last_error_code | text / text | YES | text |

Constraints:
- `prc_process_daemon_heartbeats_daemon_check`: `CHECK ((daemon = ANY (ARRAY['relay'::text, 'reconcile'::text, 'feed'::text, 'project'::text])))`
- `prc_process_daemon_heartbeats_pkey`: `PRIMARY KEY (instance_id)`

Indexes:
- `prc_process_daemon_heartbeats_pkey`: `CREATE UNIQUE INDEX prc_process_daemon_heartbeats_pkey ON public.prc_process_daemon_heartbeats USING btree (instance_id)`

#### prc_process_handler_holds

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| stage | text / text | NO | text NOT NULL |
| handler_version | text / text | NO | text NOT NULL |
| state | text / text | NO | text DEFAULT 'healthy'::text NOT NULL |
| version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| fault_signature | bytea / bytea | YES | bytea |
| incident_id | uuid / uuid | YES | uuid |
| held_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_process_handler_holds_fault_signature_check`: `CHECK (((fault_signature IS NULL) OR (octet_length(fault_signature) = 32)))`
- `prc_process_handler_holds_held_check`: `CHECK (((state <> 'held'::text) OR ((fault_signature IS NOT NULL) AND (incident_id IS NOT NULL) AND (held_at IS NOT NULL))))`
- `prc_process_handler_holds_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES prc_process_incidents(id)`
- `prc_process_handler_holds_pkey`: `PRIMARY KEY (id)`
- `prc_process_handler_holds_stage_check`: `CHECK ((stage = ANY (ARRAY['listing'::text, 'detail'::text, 'status_refresh'::text, 'download'::text, 'prepare'::text, 'screening'::text, 'extraction'::text, 'publication'::text])))`
- `prc_process_handler_holds_stage_version_unique`: `UNIQUE (stage, handler_version)`
- `prc_process_handler_holds_state_check`: `CHECK ((state = ANY (ARRAY['healthy'::text, 'held'::text])))`
- `prc_process_handler_holds_version_check`: `CHECK ((version >= 1))`

Indexes:
- `prc_process_handler_holds_pkey`: `CREATE UNIQUE INDEX prc_process_handler_holds_pkey ON public.prc_process_handler_holds USING btree (id)`
- `prc_process_handler_holds_stage_version_unique`: `CREATE UNIQUE INDEX prc_process_handler_holds_stage_version_unique ON public.prc_process_handler_holds USING btree (stage, handler_version)`

#### prc_process_incidents

Owner: Repositories/Procurement/Process; retention: incidents.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| fingerprint | bytea / bytea | NO | bytea NOT NULL |
| scope_key | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | YES | bigint |
| kind | text / text | NO | text NOT NULL |
| state | text / text | NO | text NOT NULL |
| first_seen_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| last_seen_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| occurrences | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| transition_seq | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| evidence | jsonb / jsonb | NO | jsonb NOT NULL |
| evidence_schema_version | smallint / int2 | NO | smallint NOT NULL |
| acknowledged_by | bigint / int8 | YES | bigint |
| resolved_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_process_incidents_acknowledged_by_fkey`: `FOREIGN KEY (acknowledged_by) REFERENCES users(id)`
- `prc_process_incidents_evidence_schema_version_check`: `CHECK ((evidence_schema_version > 0))`
- `prc_process_incidents_fingerprint_check`: `CHECK ((octet_length(fingerprint) = 32))`
- `prc_process_incidents_occurrences_check`: `CHECK ((occurrences >= 1))`
- `prc_process_incidents_pkey`: `PRIMARY KEY (id)`
- `prc_process_incidents_state_check`: `CHECK ((state = ANY (ARRAY['open'::text, 'acknowledged'::text, 'resolved'::text])))`
- `prc_process_incidents_transition_seq_check`: `CHECK ((transition_seq >= 0))`

Indexes:
- `prc_process_incidents_active_fingerprint_unique`: `CREATE UNIQUE INDEX prc_process_incidents_active_fingerprint_unique ON public.prc_process_incidents USING btree (fingerprint) WHERE (state <> 'resolved'::text)`
- `prc_process_incidents_pkey`: `CREATE UNIQUE INDEX prc_process_incidents_pkey ON public.prc_process_incidents USING btree (id)`
- `prc_process_incidents_state_seen_idx`: `CREATE INDEX prc_process_incidents_state_seen_idx ON public.prc_process_incidents USING btree (state, last_seen_at DESC)`

#### prc_process_lanes

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| lane_key | text / text | NO | text NOT NULL |
| parent_lane_id | uuid / uuid | YES | uuid |
| max_outstanding | integer / int4 | NO | integer NOT NULL |
| max_running | integer / int4 | NO | integer NOT NULL |
| low_watermark | integer / int4 | NO | integer NOT NULL |
| refill_max | integer / int4 | NO | integer NOT NULL |
| policy_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| fairness_cursor | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| fairness_cursor_schema_version | smallint / int2 | NO | smallint DEFAULT 1 NOT NULL |

Constraints:
- `prc_process_lanes_check`: `CHECK (((max_running > 0) AND (max_running <= max_outstanding)))`
- `prc_process_lanes_check1`: `CHECK (((low_watermark >= 0) AND (low_watermark < max_outstanding)))`
- `prc_process_lanes_check2`: `CHECK (((refill_max > 0) AND (refill_max <= max_outstanding)))`
- `prc_process_lanes_fairness_cursor_schema_version_check`: `CHECK ((fairness_cursor_schema_version > 0))`
- `prc_process_lanes_lane_key_unique`: `UNIQUE (lane_key)`
- `prc_process_lanes_max_outstanding_check`: `CHECK ((max_outstanding > 0))`
- `prc_process_lanes_parent_lane_id_fkey`: `FOREIGN KEY (parent_lane_id) REFERENCES prc_process_lanes(id)`
- `prc_process_lanes_pkey`: `PRIMARY KEY (id)`
- `prc_process_lanes_policy_version_check`: `CHECK ((policy_version >= 1))`

Indexes:
- `prc_process_lanes_lane_key_unique`: `CREATE UNIQUE INDEX prc_process_lanes_lane_key_unique ON public.prc_process_lanes USING btree (lane_key)`
- `prc_process_lanes_pkey`: `CREATE UNIQUE INDEX prc_process_lanes_pkey ON public.prc_process_lanes USING btree (id)`

#### prc_process_metric_totals

Owner: Repositories/Procurement/Process; retention: projection.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| projector_key | text / text | NO | text NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| metric_key | text / text | NO | text NOT NULL |
| dimension_key | text / text | NO | text NOT NULL |
| value | bigint / int8 | NO | bigint NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_process_metric_totals_generation_check`: `CHECK ((generation > 0))`
- `prc_process_metric_totals_pkey`: `PRIMARY KEY (projector_key, generation, metric_key, dimension_key)`
- `prc_process_metric_totals_projector_key_fkey`: `FOREIGN KEY (projector_key) REFERENCES prc_process_projection_states(projector_key)`
- `prc_process_metric_totals_value_check`: `CHECK ((value >= 0))`

Indexes:
- `prc_process_metric_totals_pkey`: `CREATE UNIQUE INDEX prc_process_metric_totals_pkey ON public.prc_process_metric_totals USING btree (projector_key, generation, metric_key, dimension_key)`

#### prc_process_outbox

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| message_id | uuid / uuid | NO | uuid NOT NULL |
| dedup_key | text / text | NO | text NOT NULL |
| kind | text / text | NO | text NOT NULL |
| payload | jsonb / jsonb | NO | jsonb NOT NULL |
| payload_schema_version | smallint / int2 | NO | smallint NOT NULL |
| available_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| claim_token | uuid / uuid | YES | uuid |
| claim_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| published_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| attempts | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| last_error_code | text / text | YES | text |

Constraints:
- `prc_process_outbox_attempts_check`: `CHECK ((attempts >= 0))`
- `prc_process_outbox_dedup_key_unique`: `UNIQUE (dedup_key)`
- `prc_process_outbox_kind_check`: `CHECK ((kind = ANY (ARRAY['work_wake'::text, 'feeder_wake'::text, 'projection'::text])))`
- `prc_process_outbox_payload_schema_version_check`: `CHECK ((payload_schema_version > 0))`
- `prc_process_outbox_pkey`: `PRIMARY KEY (message_id)`

Indexes:
- `prc_process_outbox_dedup_key_unique`: `CREATE UNIQUE INDEX prc_process_outbox_dedup_key_unique ON public.prc_process_outbox USING btree (dedup_key)`
- `prc_process_outbox_pending_idx`: `CREATE INDEX prc_process_outbox_pending_idx ON public.prc_process_outbox USING btree (kind, available_at, message_id) WHERE (published_at IS NULL)`
- `prc_process_outbox_pkey`: `CREATE UNIQUE INDEX prc_process_outbox_pkey ON public.prc_process_outbox USING btree (message_id)`
- `prc_process_outbox_published_idx`: `CREATE INDEX prc_process_outbox_published_idx ON public.prc_process_outbox USING btree (published_at, message_id) WHERE (published_at IS NOT NULL)`

#### prc_process_projection_receipts

Owner: Repositories/Procurement/Process; retention: projection.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| projector_key | text / text | NO | text NOT NULL |
| event_id | uuid / uuid | NO | uuid NOT NULL |
| applied_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_process_projection_receipts_event_id_fkey`: `FOREIGN KEY (event_id) REFERENCES prc_process_transitions(event_id) ON DELETE RESTRICT`
- `prc_process_projection_receipts_pkey`: `PRIMARY KEY (projector_key, event_id)`
- `prc_process_projection_receipts_projector_key_fkey`: `FOREIGN KEY (projector_key) REFERENCES prc_process_projection_states(projector_key)`

Indexes:
- `prc_process_projection_receipts_event_idx`: `CREATE INDEX prc_process_projection_receipts_event_idx ON public.prc_process_projection_receipts USING btree (event_id)`
- `prc_process_projection_receipts_pkey`: `CREATE UNIQUE INDEX prc_process_projection_receipts_pkey ON public.prc_process_projection_receipts USING btree (projector_key, event_id)`

#### prc_process_projection_states

Owner: Repositories/Procurement/Process; retention: projection.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| projector_key | text / text | NO | text NOT NULL |
| mode | text / text | NO | text NOT NULL |
| active_generation | bigint / int8 | NO | bigint NOT NULL |
| catalog_hash | bytea / bytea | NO | bytea NOT NULL |
| history_complete | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| history_pruned_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| maintenance_token | uuid / uuid | YES | uuid |
| maintenance_started_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| rebuilt_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_process_projection_states_active_generation_check`: `CHECK ((active_generation > 0))`
- `prc_process_projection_states_catalog_hash_check`: `CHECK ((octet_length(catalog_hash) = 32))`
- `prc_process_projection_states_history_check`: `CHECK ((history_complete = (history_pruned_at IS NULL)))`
- `prc_process_projection_states_maintenance_started_check`: `CHECK (((mode = 'maintenance'::text) = (maintenance_started_at IS NOT NULL)))`
- `prc_process_projection_states_maintenance_token_check`: `CHECK (((mode = 'maintenance'::text) = (maintenance_token IS NOT NULL)))`
- `prc_process_projection_states_mode_check`: `CHECK ((mode = ANY (ARRAY['active'::text, 'maintenance'::text])))`
- `prc_process_projection_states_pkey`: `PRIMARY KEY (projector_key)`

Indexes:
- `prc_process_projection_states_pkey`: `CREATE UNIQUE INDEX prc_process_projection_states_pkey ON public.prc_process_projection_states USING btree (projector_key)`

#### prc_process_reservations

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| admission_group_id | uuid / uuid | NO | uuid NOT NULL |
| lane_id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | NO | uuid NOT NULL |
| stage | text / text | NO | text NOT NULL |
| state | text / text | NO | text NOT NULL |

Constraints:
- `prc_process_reservations_admission_group_id_fkey`: `FOREIGN KEY (admission_group_id) REFERENCES prc_admission_groups(id) ON DELETE CASCADE`
- `prc_process_reservations_lane_id_fkey`: `FOREIGN KEY (lane_id) REFERENCES prc_process_lanes(id)`
- `prc_process_reservations_pkey`: `PRIMARY KEY (admission_group_id, lane_id)`
- `prc_process_reservations_stage_check`: `CHECK ((stage = ANY (ARRAY['listing'::text, 'detail'::text, 'status_refresh'::text, 'download'::text, 'prepare'::text, 'screening'::text, 'extraction'::text, 'publication'::text])))`
- `prc_process_reservations_state_check`: `CHECK ((state = ANY (ARRAY['admitted'::text, 'running'::text])))`
- `prc_process_reservations_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_process_reservations_lane_state_idx`: `CREATE INDEX prc_process_reservations_lane_state_idx ON public.prc_process_reservations USING btree (lane_id, state)`
- `prc_process_reservations_pkey`: `CREATE UNIQUE INDEX prc_process_reservations_pkey ON public.prc_process_reservations USING btree (admission_group_id, lane_id)`

#### prc_process_run_work

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| run_id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | NO | uuid NOT NULL |
| relation | text / text | NO | text NOT NULL |
| accounting_role | text / text | NO | text NOT NULL |

Constraints:
- `prc_process_run_work_accounting_role_check`: `CHECK ((accounting_role = ANY (ARRAY['required'::text, 'observes'::text])))`
- `prc_process_run_work_pkey`: `PRIMARY KEY (run_id, work_id)`
- `prc_process_run_work_relation_check`: `CHECK ((relation = ANY (ARRAY['created'::text, 'discovered'::text, 'observes'::text])))`
- `prc_process_run_work_run_id_fkey`: `FOREIGN KEY (run_id) REFERENCES prc_process_runs(id)`
- `prc_process_run_work_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_process_run_work_pkey`: `CREATE UNIQUE INDEX prc_process_run_work_pkey ON public.prc_process_run_work USING btree (run_id, work_id)`
- `prc_process_run_work_work_idx`: `CREATE INDEX prc_process_run_work_work_idx ON public.prc_process_run_work USING btree (work_id)`

#### prc_process_runs

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| kind | text / text | NO | text NOT NULL |
| trigger_key | text / text | YES | text |
| profile_id | bigint / int8 | YES | bigint |
| configuration_snapshot | jsonb / jsonb | NO | jsonb NOT NULL |
| configuration_schema_version | smallint / int2 | NO | smallint NOT NULL |
| configuration_hash | bytea / bytea | NO | bytea NOT NULL |
| requested_by | bigint / int8 | YES | bigint |
| requested_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| started_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| discovery_closed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| cancel_requested_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| discovery_outcome | text / text | NO | text DEFAULT 'collecting'::text NOT NULL |
| discovery_reason_code | text / text | YES | text |
| transition_seq | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| projection_version | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |

Constraints:
- `prc_process_runs_configuration_hash_check`: `CHECK ((octet_length(configuration_hash) = 32))`
- `prc_process_runs_configuration_schema_version_check`: `CHECK ((configuration_schema_version > 0))`
- `prc_process_runs_discovery_closed_check`: `CHECK (((discovery_outcome = 'collecting'::text) = (discovery_closed_at IS NULL)))`
- `prc_process_runs_discovery_outcome_check`: `CHECK ((discovery_outcome = ANY (ARRAY['collecting'::text, 'complete'::text, 'incomplete'::text])))`
- `prc_process_runs_discovery_reason_check`: `CHECK (((discovery_outcome = 'incomplete'::text) = (discovery_reason_code IS NOT NULL)))`
- `prc_process_runs_kind_check`: `CHECK ((kind = ANY (ARRAY['profile'::text, 'schedule'::text, 'manual'::text, 'recovery'::text, 'eval'::text])))`
- `prc_process_runs_pkey`: `PRIMARY KEY (id)`
- `prc_process_runs_profile_id_fkey`: `FOREIGN KEY (profile_id) REFERENCES prc_crawlers(id)`
- `prc_process_runs_projection_version_check`: `CHECK ((projection_version >= 0))`
- `prc_process_runs_requested_by_fkey`: `FOREIGN KEY (requested_by) REFERENCES users(id)`
- `prc_process_runs_transition_seq_check`: `CHECK ((transition_seq >= 0))`
- `prc_process_runs_trigger_key_unique`: `UNIQUE (trigger_key)`

Indexes:
- `prc_process_runs_pkey`: `CREATE UNIQUE INDEX prc_process_runs_pkey ON public.prc_process_runs USING btree (id)`
- `prc_process_runs_requested_idx`: `CREATE INDEX prc_process_runs_requested_idx ON public.prc_process_runs USING btree (requested_at DESC)`
- `prc_process_runs_trigger_key_unique`: `CREATE UNIQUE INDEX prc_process_runs_trigger_key_unique ON public.prc_process_runs USING btree (trigger_key)`

#### prc_process_transitions

Owner: Repositories/Procurement/Process; retention: journal.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| event_id | uuid / uuid | NO | uuid NOT NULL |
| entity_kind | text / text | NO | text NOT NULL |
| work_id | uuid / uuid | YES | uuid |
| run_id | uuid / uuid | YES | uuid |
| control_id | uuid / uuid | YES | uuid |
| incident_id | uuid / uuid | YES | uuid |
| entity_seq | bigint / int8 | NO | bigint NOT NULL |
| previous_state | text / text | YES | text |
| new_state | text / text | NO | text NOT NULL |
| reason_code | text / text | YES | text |
| actor_kind | text / text | NO | text NOT NULL |
| actor_id | bigint / int8 | YES | bigint |
| generation | bigint / int8 | YES | bigint |
| details | jsonb / jsonb | YES | jsonb |
| details_schema_version | smallint / int2 | YES | smallint |
| occurred_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| schema_version | smallint / int2 | NO | smallint NOT NULL |

Constraints:
- `prc_process_transitions_actor_kind_check`: `CHECK ((actor_kind = ANY (ARRAY['system'::text, 'operator'::text, 'worker'::text, 'reconciler'::text])))`
- `prc_process_transitions_control_id_fkey`: `FOREIGN KEY (control_id) REFERENCES prc_control_scopes(id)`
- `prc_process_transitions_details_schema_check`: `CHECK (((details IS NULL) = (details_schema_version IS NULL)))`
- `prc_process_transitions_details_schema_version_check`: `CHECK ((details_schema_version > 0))`
- `prc_process_transitions_entity_check`: `CHECK (((num_nonnulls(work_id, run_id, control_id, incident_id) = 1) AND (((entity_kind = 'work'::text) AND (work_id IS NOT NULL)) OR ((entity_kind = 'run'::text) AND (run_id IS NOT NULL)) OR ((entity_kind = 'control'::text) AND (control_id IS NOT NULL)) OR ((entity_kind = 'incident'::text) AND (incident_id IS NOT NULL)))))`
- `prc_process_transitions_entity_kind_check`: `CHECK ((entity_kind = ANY (ARRAY['work'::text, 'run'::text, 'control'::text, 'incident'::text])))`
- `prc_process_transitions_entity_seq_check`: `CHECK ((entity_seq >= 1))`
- `prc_process_transitions_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES prc_process_incidents(id)`
- `prc_process_transitions_pkey`: `PRIMARY KEY (event_id)`
- `prc_process_transitions_run_id_fkey`: `FOREIGN KEY (run_id) REFERENCES prc_process_runs(id)`
- `prc_process_transitions_schema_version_check`: `CHECK ((schema_version > 0))`
- `prc_process_transitions_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_process_transitions_control_seq_unique`: `CREATE UNIQUE INDEX prc_process_transitions_control_seq_unique ON public.prc_process_transitions USING btree (control_id, entity_seq) WHERE (control_id IS NOT NULL)`
- `prc_process_transitions_incident_seq_unique`: `CREATE UNIQUE INDEX prc_process_transitions_incident_seq_unique ON public.prc_process_transitions USING btree (incident_id, entity_seq) WHERE (incident_id IS NOT NULL)`
- `prc_process_transitions_occurred_idx`: `CREATE INDEX prc_process_transitions_occurred_idx ON public.prc_process_transitions USING btree (occurred_at, event_id)`
- `prc_process_transitions_pkey`: `CREATE UNIQUE INDEX prc_process_transitions_pkey ON public.prc_process_transitions USING btree (event_id)`
- `prc_process_transitions_run_seq_unique`: `CREATE UNIQUE INDEX prc_process_transitions_run_seq_unique ON public.prc_process_transitions USING btree (run_id, entity_seq) WHERE (run_id IS NOT NULL)`
- `prc_process_transitions_run_time_idx`: `CREATE INDEX prc_process_transitions_run_time_idx ON public.prc_process_transitions USING btree (run_id, occurred_at, event_id)`
- `prc_process_transitions_work_seq_unique`: `CREATE UNIQUE INDEX prc_process_transitions_work_seq_unique ON public.prc_process_transitions USING btree (work_id, entity_seq) WHERE (work_id IS NOT NULL)`
- `prc_process_transitions_work_state_time_idx`: `CREATE INDEX prc_process_transitions_work_state_time_idx ON public.prc_process_transitions USING btree (entity_kind, new_state, occurred_at) WHERE (entity_kind = 'work'::text)`
- `prc_process_transitions_work_time_idx`: `CREATE INDEX prc_process_transitions_work_time_idx ON public.prc_process_transitions USING btree (work_id, occurred_at, event_id)`

#### prc_process_work

Owner: Repositories/Procurement/Process; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| stage | text / text | NO | text NOT NULL |
| work_key | bytea / bytea | NO | bytea NOT NULL |
| work_identity | jsonb / jsonb | NO | jsonb NOT NULL |
| work_identity_schema_version | smallint / int2 | NO | smallint NOT NULL |
| intent_id | uuid / uuid | YES | uuid |
| input_revision | bigint / int8 | NO | bigint NOT NULL |
| input_hash | bytea / bytea | NO | bytea NOT NULL |
| input_payload | jsonb / jsonb | NO | jsonb NOT NULL |
| input_schema_version | smallint / int2 | NO | smallint NOT NULL |
| state | text / text | NO | text NOT NULL |
| reason_code | text / text | YES | text |
| due_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| provider_recovery_due_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| priority | smallint / int2 | NO | smallint DEFAULT 100 NOT NULL |
| platform_id | bigint / int8 | YES | bigint |
| cohort_key | text / text | NO | text NOT NULL |
| origin_run_id | uuid / uuid | YES | uuid |
| crawl_block_id | uuid / uuid | YES | uuid |
| procurement_id | bigint / int8 | YES | bigint |
| document_id | bigint / int8 | YES | bigint |
| document_version_id | bigint / int8 | YES | bigint |
| analysis_window_id | uuid / uuid | YES | uuid |
| analysis_run_id | uuid / uuid | YES | uuid |
| generation | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| owner_token | uuid / uuid | YES | uuid |
| lease_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| heartbeat_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| dispatch_generation | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| dispatch_claim_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| checkpoint | jsonb / jsonb | YES | jsonb |
| checkpoint_schema_version | smallint / int2 | YES | smallint |
| checkpoint_seq | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| execution_attempts | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| transport_attempts | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| semantic_reasks | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| item_failures | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| infrastructure_interruptions | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| transition_seq | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `prc_process_work_admitted_claim_check`: `CHECK (((state = 'admitted'::text) = (dispatch_claim_until IS NOT NULL)))`
- `prc_process_work_analysis_run_fk`: `FOREIGN KEY (analysis_run_id) REFERENCES prc_analysis_runs(id)`
- `prc_process_work_analysis_window_fk`: `FOREIGN KEY (analysis_window_id) REFERENCES prc_analysis_windows(id)`
- `prc_process_work_checkpoint_schema_check`: `CHECK (((checkpoint IS NULL) = (checkpoint_schema_version IS NULL)))`
- `prc_process_work_checkpoint_schema_version_check`: `CHECK ((checkpoint_schema_version > 0))`
- `prc_process_work_checkpoint_seq_check`: `CHECK ((checkpoint_seq >= 0))`
- `prc_process_work_crawl_block_id_fkey`: `FOREIGN KEY (crawl_block_id) REFERENCES prc_crawl_blocks(id)`
- `prc_process_work_deferred_due_check`: `CHECK (((state <> 'deferred'::text) OR (due_at IS NOT NULL)))`
- `prc_process_work_dispatch_generation_check`: `CHECK ((dispatch_generation >= 0))`
- `prc_process_work_document_id_fkey`: `FOREIGN KEY (document_id) REFERENCES prc_documents(id)`
- `prc_process_work_document_version_id_fkey`: `FOREIGN KEY (document_version_id) REFERENCES prc_document_versions(id)`
- `prc_process_work_due_state_check`: `CHECK (((due_at IS NULL) OR (state = 'deferred'::text)))`
- `prc_process_work_execution_attempts_check`: `CHECK ((execution_attempts >= 0))`
- `prc_process_work_generation_check`: `CHECK ((generation >= 0))`
- `prc_process_work_infrastructure_interruptions_check`: `CHECK ((infrastructure_interruptions >= 0))`
- `prc_process_work_input_hash_check`: `CHECK ((octet_length(input_hash) = 32))`
- `prc_process_work_input_revision_check`: `CHECK ((input_revision >= 1))`
- `prc_process_work_input_schema_version_check`: `CHECK ((input_schema_version > 0))`
- `prc_process_work_intent_id_fkey`: `FOREIGN KEY (intent_id) REFERENCES prc_operation_intents(id)`
- `prc_process_work_item_failures_check`: `CHECK (((item_failures >= 0) AND (item_failures <= 3)))`
- `prc_process_work_origin_run_id_fkey`: `FOREIGN KEY (origin_run_id) REFERENCES prc_process_runs(id)`
- `prc_process_work_owner_check`: `CHECK ((((stage = 'listing'::text) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (crawl_block_id IS NOT NULL)) OR ((stage = ANY (ARRAY['detail'::text, 'status_refresh'::text])) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (procurement_id IS NOT NULL)) OR ((stage = ANY (ARRAY['prepare'::text, 'screening'::text])) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (document_version_id IS NOT NULL)) OR ((stage = 'extraction'::text) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (analysis_window_id IS NOT NULL)) OR ((stage = 'publication'::text) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (analysis_run_id IS NOT NULL)) OR ((stage = 'download'::text) AND (num_nonnulls(crawl_block_id, procurement_id, document_id, document_version_id, analysis_window_id, analysis_run_id) = 1) AND (document_id IS NOT NULL))))`
- `prc_process_work_pkey`: `PRIMARY KEY (id)`
- `prc_process_work_platform_id_fkey`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_process_work_procurement_id_fkey`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id)`
- `prc_process_work_provider_recovery_due_check`: `CHECK ((((state = 'blocked'::text) AND (reason_code = 'provider_result_unknown'::text)) = (provider_recovery_due_at IS NOT NULL)))`
- `prc_process_work_reason_check`: `CHECK (((state <> ALL (ARRAY['deferred'::text, 'blocked'::text, 'failed_item'::text, 'cancelled'::text, 'superseded'::text])) OR (reason_code IS NOT NULL)))`
- `prc_process_work_running_lease_check`: `CHECK ((((state = 'running'::text) AND (owner_token IS NOT NULL) AND (lease_until IS NOT NULL)) OR ((state <> 'running'::text) AND (owner_token IS NULL) AND (lease_until IS NULL))))`
- `prc_process_work_semantic_reasks_check`: `CHECK ((semantic_reasks >= 0))`
- `prc_process_work_stage_check`: `CHECK ((stage = ANY (ARRAY['listing'::text, 'detail'::text, 'status_refresh'::text, 'download'::text, 'prepare'::text, 'screening'::text, 'extraction'::text, 'publication'::text])))`
- `prc_process_work_state_check`: `CHECK ((state = ANY (ARRAY['ready'::text, 'admitted'::text, 'running'::text, 'deferred'::text, 'blocked'::text, 'succeeded'::text, 'failed_item'::text, 'cancelled'::text, 'superseded'::text])))`
- `prc_process_work_transition_seq_check`: `CHECK ((transition_seq >= 0))`
- `prc_process_work_transport_attempts_check`: `CHECK ((transport_attempts >= 0))`
- `prc_process_work_work_identity_schema_version_check`: `CHECK ((work_identity_schema_version > 0))`
- `prc_process_work_work_key_check`: `CHECK ((octet_length(work_key) = 32))`
- `prc_process_work_work_key_unique`: `UNIQUE (work_key)`

Indexes:
- `prc_process_work_analysis_run_idx`: `CREATE INDEX prc_process_work_analysis_run_idx ON public.prc_process_work USING btree (analysis_run_id) WHERE (analysis_run_id IS NOT NULL)`
- `prc_process_work_analysis_window_idx`: `CREATE INDEX prc_process_work_analysis_window_idx ON public.prc_process_work USING btree (analysis_window_id) WHERE (analysis_window_id IS NOT NULL)`
- `prc_process_work_claim_idx`: `CREATE INDEX prc_process_work_claim_idx ON public.prc_process_work USING btree (dispatch_claim_until, id) WHERE (state = 'admitted'::text)`
- `prc_process_work_crawl_block_idx`: `CREATE INDEX prc_process_work_crawl_block_idx ON public.prc_process_work USING btree (crawl_block_id) WHERE (crawl_block_id IS NOT NULL)`
- `prc_process_work_detail_latest_idx`: `CREATE INDEX prc_process_work_detail_latest_idx ON public.prc_process_work USING btree (procurement_id, created_at DESC, id DESC) WHERE ((stage = 'detail'::text) AND (procurement_id IS NOT NULL))`
- `prc_process_work_document_idx`: `CREATE INDEX prc_process_work_document_idx ON public.prc_process_work USING btree (document_id) WHERE (document_id IS NOT NULL)`
- `prc_process_work_document_version_idx`: `CREATE INDEX prc_process_work_document_version_idx ON public.prc_process_work USING btree (document_version_id) WHERE (document_version_id IS NOT NULL)`
- `prc_process_work_due_idx`: `CREATE INDEX prc_process_work_due_idx ON public.prc_process_work USING btree (due_at, priority, id) WHERE (state = ANY (ARRAY['ready'::text, 'deferred'::text]))`
- `prc_process_work_fairness_idx`: `CREATE INDEX prc_process_work_fairness_idx ON public.prc_process_work USING btree (stage, cohort_key, created_at, id) WHERE (state = ANY (ARRAY['ready'::text, 'deferred'::text]))`
- `prc_process_work_lease_idx`: `CREATE INDEX prc_process_work_lease_idx ON public.prc_process_work USING btree (lease_until, id) WHERE (state = 'running'::text)`
- `prc_process_work_pkey`: `CREATE UNIQUE INDEX prc_process_work_pkey ON public.prc_process_work USING btree (id)`
- `prc_process_work_procurement_idx`: `CREATE INDEX prc_process_work_procurement_idx ON public.prc_process_work USING btree (procurement_id) WHERE (procurement_id IS NOT NULL)`
- `prc_process_work_provider_recovery_due_idx`: `CREATE INDEX prc_process_work_provider_recovery_due_idx ON public.prc_process_work USING btree (provider_recovery_due_at, id) WHERE ((state = 'blocked'::text) AND (reason_code = 'provider_result_unknown'::text))`
- `prc_process_work_run_idx`: `CREATE INDEX prc_process_work_run_idx ON public.prc_process_work USING btree (origin_run_id, stage, state)`
- `prc_process_work_updated_idx`: `CREATE INDEX prc_process_work_updated_idx ON public.prc_process_work USING btree (updated_at DESC)`
- `prc_process_work_work_key_unique`: `CREATE UNIQUE INDEX prc_process_work_work_key_unique ON public.prc_process_work USING btree (work_key)`

#### prc_procurement_documents

Owner: DocumentCatalogRepository; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| document_id | bigint / int8 | NO | bigint NOT NULL |
| observed_at | timestamp with time zone / timestamptz | NO | timestamp(6) with time zone NOT NULL |
| removed_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |

Constraints:
- `prc_procurement_documents_document_id_foreign`: `FOREIGN KEY (document_id) REFERENCES prc_documents(id)`
- `prc_procurement_documents_pkey`: `PRIMARY KEY (procurement_id, document_id)`
- `prc_procurement_documents_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id)`

Indexes:
- `prc_procurement_documents_pkey`: `CREATE UNIQUE INDEX prc_procurement_documents_pkey ON public.prc_procurement_documents USING btree (procurement_id, document_id)`

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
| platform_id | bigint / int8 | NO | bigint NOT NULL |
| registry_id | bigint / int8 | NO | bigint NOT NULL |
| source_identity_type | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| source_identity_value | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
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
| succeeded_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
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
| head_generation | bigint / int8 | NO | bigint DEFAULT '1'::bigint NOT NULL |
| detail_cycle_seq | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |
| status_cycle_seq | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |
| next_status_due_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| source_updated_has_day_precision | boolean / bool | YES | boolean |
| source_updated_before | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
| source_observed_at | timestamp with time zone / timestamptz | YES | timestamp(6) with time zone |
| source_order_ambiguous | boolean / bool | NO | boolean DEFAULT false NOT NULL |

Constraints:
- `prc_procurements_customer_org_id_foreign`: `FOREIGN KEY (customer_org_id) REFERENCES org_organizations(id) ON DELETE SET NULL`
- `prc_procurements_detail_cycle_seq_check`: `CHECK ((detail_cycle_seq >= 0))`
- `prc_procurements_head_generation_check`: `CHECK ((head_generation >= 1))`
- `prc_procurements_pkey`: `PRIMARY KEY (id)`
- `prc_procurements_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_procurements_registry_platform_fk`: `FOREIGN KEY (registry_id, platform_id) REFERENCES prc_registries(id, platform_id)`
- `prc_procurements_source_identity_type_check`: `CHECK ((source_identity_type ~ '^[a-z][a-z0-9_.:-]{0,95}$'::text))`
- `prc_procurements_source_identity_unique`: `UNIQUE (platform_id, source_identity_type, source_identity_value)`
- `prc_procurements_source_identity_value_check`: `CHECK (((char_length(source_identity_value) >= 1) AND (char_length(source_identity_value) <= 200)))`
- `prc_procurements_status_cycle_seq_check`: `CHECK ((status_cycle_seq >= 0))`
- `prc_procurements_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_procurements_customer_org_id_index`: `CREATE INDEX prc_procurements_customer_org_id_index ON public.prc_procurements USING btree (customer_org_id)`
- `prc_procurements_okved_additional_index`: `CREATE INDEX prc_procurements_okved_additional_index ON public.prc_procurements USING btree (okved_additional)`
- `prc_procurements_okved_code_index`: `CREATE INDEX prc_procurements_okved_code_index ON public.prc_procurements USING btree (okved_code)`
- `prc_procurements_pkey`: `CREATE UNIQUE INDEX prc_procurements_pkey ON public.prc_procurements USING btree (id)`
- `prc_procurements_published_at_index`: `CREATE INDEX prc_procurements_published_at_index ON public.prc_procurements USING btree (published_at)`
- `prc_procurements_region_code_index`: `CREATE INDEX prc_procurements_region_code_index ON public.prc_procurements USING btree (region_code)`
- `prc_procurements_registry_id_status_published_at_index`: `CREATE INDEX prc_procurements_registry_id_status_published_at_index ON public.prc_procurements USING btree (registry_id, status, published_at)`
- `prc_procurements_source_identity_unique`: `CREATE UNIQUE INDEX prc_procurements_source_identity_unique ON public.prc_procurements USING btree (platform_id, source_identity_type, source_identity_value)`
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
| country | character / bpchar | YES | character(2) |
| scopes | jsonb / jsonb | NO | jsonb DEFAULT '[]'::jsonb NOT NULL |
| created_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| updated_at | timestamp without time zone / timestamp | YES | timestamp(0) without time zone |
| route_id | uuid / uuid | NO | uuid NOT NULL |

Constraints:
- `prc_proxies_pkey`: `PRIMARY KEY (id)`
- `prc_proxies_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `prc_proxies_route_id_key`: `UNIQUE (route_id)`

Indexes:
- `prc_proxies_country_index`: `CREATE INDEX prc_proxies_country_index ON public.prc_proxies USING btree (country)`
- `prc_proxies_pkey`: `CREATE UNIQUE INDEX prc_proxies_pkey ON public.prc_proxies USING btree (id)`
- `prc_proxies_route_id_key`: `CREATE UNIQUE INDEX prc_proxies_route_id_key ON public.prc_proxies USING btree (route_id)`
- `prc_proxies_scopes_gin`: `CREATE INDEX prc_proxies_scopes_gin ON public.prc_proxies USING gin (scopes)`

#### prc_proxyma_binding_links

Owner: Services/Procurement/Proxyma; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| crawler_id | bigint / int8 | NO | bigint NOT NULL |
| slot | text / text | NO | text NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| binding_id | uuid / uuid | NO | uuid NOT NULL |

Constraints:
- `prc_proxyma_binding_links_crawler_id_foreign`: `FOREIGN KEY (crawler_id) REFERENCES prc_crawlers(id)`
- `prc_proxyma_binding_links_pkey`: `PRIMARY KEY (crawler_id, slot)`
- `prc_proxyma_binding_links_valid`: `CHECK ((slot = ANY (ARRAY['listing'::text, 'detail'::text, 'status_refresh'::text, 'download'::text, 'reference'::text, 'llm'::text])))`
- `prc_pxm_binding_identity`: `UNIQUE (tenant_key, binding_id)`

Indexes:
- `prc_proxyma_binding_links_pkey`: `CREATE UNIQUE INDEX prc_proxyma_binding_links_pkey ON public.prc_proxyma_binding_links USING btree (crawler_id, slot)`
- `prc_pxm_binding_identity`: `CREATE UNIQUE INDEX prc_pxm_binding_identity ON public.prc_proxyma_binding_links USING btree (tenant_key, binding_id)`

#### prc_proxyma_endpoint_links

Owner: Services/Procurement/Proxyma/Migration; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| proxy_id | bigint / int8 | NO | bigint NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| endpoint_id | uuid / uuid | NO | uuid NOT NULL |

Constraints:
- `prc_proxyma_endpoint_links_pkey`: `PRIMARY KEY (proxy_id)`
- `prc_proxyma_endpoint_links_proxy_id_foreign`: `FOREIGN KEY (proxy_id) REFERENCES prc_proxies(id)`
- `prc_proxyma_endpoint_links_tenant_key_endpoint_id_unique`: `UNIQUE (tenant_key, endpoint_id)`

Indexes:
- `prc_proxyma_endpoint_links_pkey`: `CREATE UNIQUE INDEX prc_proxyma_endpoint_links_pkey ON public.prc_proxyma_endpoint_links USING btree (proxy_id)`
- `prc_proxyma_endpoint_links_tenant_key_endpoint_id_unique`: `CREATE UNIQUE INDEX prc_proxyma_endpoint_links_tenant_key_endpoint_id_unique ON public.prc_proxyma_endpoint_links USING btree (tenant_key, endpoint_id)`

#### prc_proxyma_owner_links

Owner: Services/Procurement/Proxyma; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| tenant_key | text / text | NO | text NOT NULL |
| owner_type | text / text | NO | text NOT NULL |
| owner_id | uuid / uuid | NO | uuid NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| parent_attempt_id | uuid / uuid | YES | uuid |

Constraints:
- `prc_proxyma_owner_links_parent_attempt_id_foreign`: `FOREIGN KEY (parent_attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_proxyma_owner_links_pkey`: `PRIMARY KEY (tenant_key, owner_type, owner_id, generation)`
- `prc_proxyma_owner_links_valid`: `CHECK (((generation > 0) AND (owner_type = ANY (ARRAY['process_attempt'::text, 'outbound_operation'::text]))))`
- `prc_pxm_owner_identity`: `UNIQUE (tenant_key, operation_id)`

Indexes:
- `prc_proxyma_owner_links_pkey`: `CREATE UNIQUE INDEX prc_proxyma_owner_links_pkey ON public.prc_proxyma_owner_links USING btree (tenant_key, owner_type, owner_id, generation)`
- `prc_pxm_owner_identity`: `CREATE UNIQUE INDEX prc_pxm_owner_identity ON public.prc_proxyma_owner_links USING btree (tenant_key, operation_id)`

#### prc_proxyma_profile_links

Owner: Services/Procurement/Proxyma; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| source_profile_id | uuid / uuid | NO | uuid NOT NULL |
| source_version | bigint / int8 | NO | bigint NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| profile_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_proxyma_profile_links_pkey`: `PRIMARY KEY (source_profile_id, source_version)`
- `prc_proxyma_profile_links_source_profile_id_foreign`: `FOREIGN KEY (source_profile_id) REFERENCES prc_network_route_profiles(id)`
- `prc_proxyma_profile_links_valid`: `CHECK (((source_version > 0) AND (profile_version > 0)))`
- `prc_pxm_profile_identity`: `UNIQUE (tenant_key, profile_id, profile_version)`

Indexes:
- `prc_proxyma_profile_links_pkey`: `CREATE UNIQUE INDEX prc_proxyma_profile_links_pkey ON public.prc_proxyma_profile_links USING btree (source_profile_id, source_version)`
- `prc_pxm_profile_identity`: `CREATE UNIQUE INDEX prc_pxm_profile_identity ON public.prc_proxyma_profile_links USING btree (tenant_key, profile_id, profile_version)`

#### prc_proxyma_state_archive

Owner: Services/Procurement/Proxyma/Migration; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| source_table | text / text | NO | text NOT NULL |
| source_key | text / text | NO | text NOT NULL |
| payload | jsonb / jsonb | NO | jsonb NOT NULL |
| mapped_state | text / text | YES | text |
| authorized_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |

Constraints:
- `prc_proxyma_state_archive_check`: `CHECK (((mapped_state IS NULL) OR ((mapped_state = ANY (ARRAY['cancelled_before_send'::text, 'expired_before_send'::text])) AND (authorized_at IS NULL)) OR ((mapped_state = ANY (ARRAY['completed'::text, 'unknown_after_authorization'::text])) AND (authorized_at IS NOT NULL))))`
- `prc_proxyma_state_archive_pkey`: `PRIMARY KEY (source_table, source_key)`

Indexes:
- `prc_proxyma_state_archive_pkey`: `CREATE UNIQUE INDEX prc_proxyma_state_archive_pkey ON public.prc_proxyma_state_archive USING btree (source_table, source_key)`

#### prc_proxyma_target_links

Owner: Services/Procurement/Proxyma; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| source_target_id | uuid / uuid | NO | uuid NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| source_revision | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_proxyma_target_links_pkey`: `PRIMARY KEY (source_target_id)`
- `prc_proxyma_target_links_source_target_id_foreign`: `FOREIGN KEY (source_target_id) REFERENCES prc_source_targets(id)`
- `prc_proxyma_target_links_valid`: `CHECK ((source_revision > 0))`
- `prc_pxm_target_identity`: `UNIQUE (tenant_key, target_id)`

Indexes:
- `prc_proxyma_target_links_pkey`: `CREATE UNIQUE INDEX prc_proxyma_target_links_pkey ON public.prc_proxyma_target_links USING btree (source_target_id)`
- `prc_pxm_target_identity`: `CREATE UNIQUE INDEX prc_pxm_target_identity ON public.prc_proxyma_target_links USING btree (tenant_key, target_id)`

#### prc_proxyma_transfer_records

Owner: Services/Procurement/Proxyma/Migration; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| target_table | text / text | NO | text NOT NULL |
| target_key | text / text | NO | text NOT NULL |
| cutover_id | uuid / uuid | NO | uuid NOT NULL |
| identity | jsonb / jsonb | NO | jsonb NOT NULL |
| imported | jsonb / jsonb | NO | jsonb NOT NULL |
| checksum | character / bpchar | NO | character(64) NOT NULL |

Constraints:
- `prc_proxyma_transfer_records_check`: `CHECK (((checksum ~ '^[0-9a-f]{64}$'::text) AND (jsonb_typeof(identity) = 'object'::text) AND (jsonb_typeof(imported) = 'object'::text)))`
- `prc_proxyma_transfer_records_cutover_id_foreign`: `FOREIGN KEY (cutover_id) REFERENCES prc_network_cutover_journals(id)`
- `prc_proxyma_transfer_records_pkey`: `PRIMARY KEY (target_table, target_key)`

Indexes:
- `prc_proxyma_transfer_records_pkey`: `CREATE UNIQUE INDEX prc_proxyma_transfer_records_pkey ON public.prc_proxyma_transfer_records USING btree (target_table, target_key)`

#### prc_raw_artifacts

Owner: Procurement; retention: artifacts.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | character varying / varchar | NO | character varying(64) NOT NULL |
| deletion_generation | bigint / int8 | NO | bigint DEFAULT '0'::bigint NOT NULL |
| deletion_marked_at | timestamp with time zone / timestamptz | YES | timestamp(0) with time zone |
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
- `prc_registries_id_platform_unique`: `UNIQUE (id, platform_id)`
- `prc_registries_pkey`: `PRIMARY KEY (id)`
- `prc_registries_platform_id_code_unique`: `UNIQUE (platform_id, code)`
- `prc_registries_platform_id_foreign`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id) ON DELETE CASCADE`
- `prc_registries_uuid_unique`: `UNIQUE (uuid)`

Indexes:
- `prc_registries_id_platform_unique`: `CREATE UNIQUE INDEX prc_registries_id_platform_unique ON public.prc_registries USING btree (id, platform_id)`
- `prc_registries_pkey`: `CREATE UNIQUE INDEX prc_registries_pkey ON public.prc_registries USING btree (id)`
- `prc_registries_platform_id_code_unique`: `CREATE UNIQUE INDEX prc_registries_platform_id_code_unique ON public.prc_registries USING btree (platform_id, code)`
- `prc_registries_uuid_unique`: `CREATE UNIQUE INDEX prc_registries_uuid_unique ON public.prc_registries USING btree (uuid)`

#### prc_request_attempts

Owner: Repositories/Procurement/Network; retention: requests.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| request_id | uuid / uuid | NO | uuid NOT NULL |
| attempt_id | uuid / uuid | YES | uuid |
| demand_id | uuid / uuid | YES | uuid |
| request_seq | bigint / int8 | NO | bigint NOT NULL |
| target_id | uuid / uuid | YES | uuid |
| route_id | uuid / uuid | YES | uuid |
| started_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| sent_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| finished_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| phase | text / text | NO | text NOT NULL |
| outcome | text / text | YES | text |
| bytes | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| status | smallint / int2 | YES | smallint |
| duration_ms | bigint / int8 | YES | bigint |
| epochs | jsonb / jsonb | NO | jsonb NOT NULL |
| epochs_schema_version | smallint / int2 | NO | smallint NOT NULL |
| artifacts | jsonb / jsonb | YES | jsonb |
| artifacts_schema_version | smallint / int2 | YES | smallint |

Constraints:
- `prc_request_attempts_artifacts_schema_check`: `CHECK (((artifacts IS NULL) = (artifacts_schema_version IS NULL)))`
- `prc_request_attempts_artifacts_schema_version_check`: `CHECK ((artifacts_schema_version > 0))`
- `prc_request_attempts_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_request_attempts_attempt_seq_unique`: `UNIQUE (attempt_id, request_seq)`
- `prc_request_attempts_bytes_check`: `CHECK ((bytes >= 0))`
- `prc_request_attempts_check`: `CHECK (((attempt_id IS NOT NULL) <> (demand_id IS NOT NULL)))`
- `prc_request_attempts_demand_id_fkey`: `FOREIGN KEY (demand_id) REFERENCES prc_network_demands(id)`
- `prc_request_attempts_demand_id_key`: `UNIQUE (demand_id)`
- `prc_request_attempts_duration_ms_check`: `CHECK ((duration_ms >= 0))`
- `prc_request_attempts_epochs_schema_version_check`: `CHECK ((epochs_schema_version > 0))`
- `prc_request_attempts_pkey`: `PRIMARY KEY (request_id)`
- `prc_request_attempts_request_seq_check`: `CHECK ((request_seq >= 1))`
- `prc_request_attempts_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `prc_request_attempts_target_id_fkey`: `FOREIGN KEY (target_id) REFERENCES prc_source_targets(id)`

Indexes:
- `prc_request_attempts_attempt_seq_unique`: `CREATE UNIQUE INDEX prc_request_attempts_attempt_seq_unique ON public.prc_request_attempts USING btree (attempt_id, request_seq)`
- `prc_request_attempts_demand_id_key`: `CREATE UNIQUE INDEX prc_request_attempts_demand_id_key ON public.prc_request_attempts USING btree (demand_id)`
- `prc_request_attempts_pkey`: `CREATE UNIQUE INDEX prc_request_attempts_pkey ON public.prc_request_attempts USING btree (request_id)`
- `prc_request_attempts_started_idx`: `CREATE INDEX prc_request_attempts_started_idx ON public.prc_request_attempts USING btree (started_at)`

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
| process_run_id | uuid / uuid | YES | uuid |
| created_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| updated_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_revisions_pkey`: `PRIMARY KEY (id, detected_at)`
- `prc_revisions_process_run_fk`: `FOREIGN KEY (process_run_id) REFERENCES prc_process_runs(id)`
- `prc_revisions_procurement_id_foreign`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id) ON DELETE RESTRICT`

Indexes:
- `prc_revisions_pkey`: `CREATE UNIQUE INDEX prc_revisions_pkey ON ONLY public.prc_revisions USING btree (id, detected_at)`
- `prc_revisions_procurement_id_index`: `CREATE INDEX prc_revisions_procurement_id_index ON ONLY public.prc_revisions USING btree (procurement_id)`
- `prc_revisions_procurement_revision_detected_unique`: `CREATE UNIQUE INDEX prc_revisions_procurement_revision_detected_unique ON ONLY public.prc_revisions USING btree (procurement_id, revision_number, detected_at)`

#### prc_route_health

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| state_version | bigint / int8 | NO | bigint NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| recent_openings | bigint / int8 | NO | bigint NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_route_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `prc_route_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `prc_route_health_pkey`: `PRIMARY KEY (id)`
- `prc_route_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `prc_route_health_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `prc_route_health_route_id_key`: `UNIQUE (route_id)`
- `prc_route_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `prc_route_health_state_version_check`: `CHECK ((state_version >= 1))`

Indexes:
- `prc_route_health_pkey`: `CREATE UNIQUE INDEX prc_route_health_pkey ON public.prc_route_health USING btree (id)`
- `prc_route_health_route_id_key`: `CREATE UNIQUE INDEX prc_route_health_route_id_key ON public.prc_route_health USING btree (route_id)`

#### prc_route_target_health

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| state_version | bigint / int8 | NO | bigint NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| recent_openings | bigint / int8 | NO | bigint NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_route_target_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `prc_route_target_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `prc_route_target_health_pkey`: `PRIMARY KEY (id)`
- `prc_route_target_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `prc_route_target_health_route_id_fkey`: `FOREIGN KEY (route_id) REFERENCES prc_network_routes(id)`
- `prc_route_target_health_route_id_target_id_key`: `UNIQUE (route_id, target_id)`
- `prc_route_target_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `prc_route_target_health_state_version_check`: `CHECK ((state_version >= 1))`
- `prc_route_target_health_target_id_fkey`: `FOREIGN KEY (target_id) REFERENCES prc_source_targets(id)`

Indexes:
- `prc_route_target_health_pkey`: `CREATE UNIQUE INDEX prc_route_target_health_pkey ON public.prc_route_target_health USING btree (id)`
- `prc_route_target_health_route_id_target_id_key`: `CREATE UNIQUE INDEX prc_route_target_health_route_id_target_id_key ON public.prc_route_target_health USING btree (route_id, target_id)`

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

#### prc_source_targets

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| platform_id | bigint / int8 | YES | bigint |
| provider_key | text / text | YES | text |
| target_key | text / text | NO | text NOT NULL |
| canonical_origin | text / text | NO | text NOT NULL |
| capability | text / text | NO | text NOT NULL |
| endpoint_policy | jsonb / jsonb | NO | jsonb NOT NULL |
| endpoint_policy_schema_version | smallint / int2 | NO | smallint NOT NULL |
| policy_version | bigint / int8 | NO | bigint NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_source_targets_check`: `CHECK ((((platform_id IS NOT NULL) AND (provider_key IS NULL)) OR ((platform_id IS NULL) AND (provider_key IS NOT NULL) AND (provider_key ~ '^[a-z][a-z0-9_.:-]{0,95}$'::text))))`
- `prc_source_targets_endpoint_policy_schema_version_check`: `CHECK ((endpoint_policy_schema_version > 0))`
- `prc_source_targets_pkey`: `PRIMARY KEY (id)`
- `prc_source_targets_platform_id_fkey`: `FOREIGN KEY (platform_id) REFERENCES prc_platforms(id)`
- `prc_source_targets_platform_id_target_key_key`: `UNIQUE (platform_id, target_key)`
- `prc_source_targets_policy_version_check`: `CHECK ((policy_version >= 1))`
- `prc_source_targets_provider_key_target_key_key`: `UNIQUE (provider_key, target_key)`

Indexes:
- `prc_source_targets_pkey`: `CREATE UNIQUE INDEX prc_source_targets_pkey ON public.prc_source_targets USING btree (id)`
- `prc_source_targets_platform_id_target_key_key`: `CREATE UNIQUE INDEX prc_source_targets_platform_id_target_key_key ON public.prc_source_targets USING btree (platform_id, target_key)`
- `prc_source_targets_provider_key_target_key_key`: `CREATE UNIQUE INDEX prc_source_targets_provider_key_target_key_key ON public.prc_source_targets USING btree (provider_key, target_key)`

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

#### prc_status_refreshes

Owner: Repositories/Procurement/Crawler; retention: business.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| procurement_id | bigint / int8 | NO | bigint NOT NULL |
| intent_id | uuid / uuid | NO | uuid NOT NULL |
| cycle_seq | bigint / int8 | NO | bigint NOT NULL |
| next_due_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| source_version | text / text | YES | text |
| observed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_status_refreshes_cycle_seq_check`: `CHECK ((cycle_seq >= 1))`
- `prc_status_refreshes_intent_id_fkey`: `FOREIGN KEY (intent_id) REFERENCES prc_operation_intents(id)`
- `prc_status_refreshes_intent_id_key`: `UNIQUE (intent_id)`
- `prc_status_refreshes_pkey`: `PRIMARY KEY (id)`
- `prc_status_refreshes_procurement_id_cycle_seq_key`: `UNIQUE (procurement_id, cycle_seq)`
- `prc_status_refreshes_procurement_id_fkey`: `FOREIGN KEY (procurement_id) REFERENCES prc_procurements(id)`

Indexes:
- `prc_status_refreshes_intent_id_key`: `CREATE UNIQUE INDEX prc_status_refreshes_intent_id_key ON public.prc_status_refreshes USING btree (intent_id)`
- `prc_status_refreshes_next_due_idx`: `CREATE INDEX prc_status_refreshes_next_due_idx ON public.prc_status_refreshes USING btree (next_due_at)`
- `prc_status_refreshes_pkey`: `CREATE UNIQUE INDEX prc_status_refreshes_pkey ON public.prc_status_refreshes USING btree (id)`
- `prc_status_refreshes_procurement_id_cycle_seq_key`: `CREATE UNIQUE INDEX prc_status_refreshes_procurement_id_cycle_seq_key ON public.prc_status_refreshes USING btree (procurement_id, cycle_seq)`

#### prc_storage_domains

Owner: Repositories/Procurement/Document; retention: artifacts.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| domain_key | text / text | NO | text NOT NULL |
| capacity_bytes | bigint / int8 | NO | bigint NOT NULL |
| high_watermark_bytes | bigint / int8 | NO | bigint NOT NULL |
| accounted_durable_bytes | bigint / int8 | NO | bigint NOT NULL |
| policy_version | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_storage_domains_capacity_bytes_check`: `CHECK ((capacity_bytes > 0))`
- `prc_storage_domains_check`: `CHECK (((high_watermark_bytes > 0) AND (high_watermark_bytes <= capacity_bytes)))`
- `prc_storage_domains_check1`: `CHECK (((accounted_durable_bytes >= 0) AND (accounted_durable_bytes <= capacity_bytes)))`
- `prc_storage_domains_domain_key_key`: `UNIQUE (domain_key)`
- `prc_storage_domains_pkey`: `PRIMARY KEY (id)`
- `prc_storage_domains_policy_version_check`: `CHECK ((policy_version >= 1))`

Indexes:
- `prc_storage_domains_domain_key_key`: `CREATE UNIQUE INDEX prc_storage_domains_domain_key_key ON public.prc_storage_domains USING btree (domain_key)`
- `prc_storage_domains_pkey`: `CREATE UNIQUE INDEX prc_storage_domains_pkey ON public.prc_storage_domains USING btree (id)`

#### prc_storage_reservations

Owner: Repositories/Procurement/Document; retention: artifacts.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| domain_id | uuid / uuid | NO | uuid NOT NULL |
| work_id | uuid / uuid | YES | uuid |
| attempt_id | uuid / uuid | YES | uuid |
| operation_id | uuid / uuid | YES | uuid |
| upload_intent_id | uuid / uuid | NO | uuid NOT NULL |
| owner_token | uuid / uuid | NO | uuid NOT NULL |
| generation | bigint / int8 | NO | bigint NOT NULL |
| byte_upper_bound | bigint / int8 | NO | bigint NOT NULL |
| phase | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| cleanup_verified_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| disk | text / text | NO | text NOT NULL |
| object_key | text / text | NO | text NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| repair_attempt_id | uuid / uuid | YES | uuid |
| repair_claimed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `prc_storage_reservations_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_storage_reservations_attempt_work_fk`: `FOREIGN KEY (attempt_id, work_id) REFERENCES prc_process_attempts(id, work_id)`
- `prc_storage_reservations_byte_upper_bound_check`: `CHECK ((byte_upper_bound > 0))`
- `prc_storage_reservations_cleanup_check`: `CHECK (((status = 'cleaned'::text) = (cleanup_verified_at IS NOT NULL)))`
- `prc_storage_reservations_disk_check`: `CHECK ((length(disk) > 0))`
- `prc_storage_reservations_disk_object_key_key`: `UNIQUE (disk, object_key)`
- `prc_storage_reservations_domain_id_fkey`: `FOREIGN KEY (domain_id) REFERENCES prc_storage_domains(id)`
- `prc_storage_reservations_generation_check`: `CHECK ((generation >= 1))`
- `prc_storage_reservations_object_key_check`: `CHECK ((length(object_key) > 0))`
- `prc_storage_reservations_operation_id_fkey`: `FOREIGN KEY (operation_id) REFERENCES prc_outbound_operations(id)`
- `prc_storage_reservations_owner_check`: `CHECK ((((work_id IS NULL) = (attempt_id IS NULL)) AND (NOT ((work_id IS NOT NULL) AND (operation_id IS NOT NULL)))))`
- `prc_storage_reservations_phase_check`: `CHECK ((phase = ANY (ARRAY['download'::text, 'prepare'::text])))`
- `prc_storage_reservations_pkey`: `PRIMARY KEY (id)`
- `prc_storage_reservations_repair_attempt_id_fkey`: `FOREIGN KEY (repair_attempt_id) REFERENCES prc_process_attempts(id)`
- `prc_storage_reservations_status_check`: `CHECK ((status = ANY (ARRAY['reserved'::text, 'writing'::text, 'staged'::text, 'cleaning'::text, 'cleaned'::text, 'transferred'::text])))`
- `prc_storage_reservations_upload_intent_id_key`: `UNIQUE (upload_intent_id)`
- `prc_storage_reservations_work_id_fkey`: `FOREIGN KEY (work_id) REFERENCES prc_process_work(id)`

Indexes:
- `prc_storage_reservations_active_idx`: `CREATE INDEX prc_storage_reservations_active_idx ON public.prc_storage_reservations USING btree (domain_id) WHERE (status = ANY (ARRAY['reserved'::text, 'writing'::text, 'staged'::text, 'cleaning'::text]))`
- `prc_storage_reservations_disk_object_key_key`: `CREATE UNIQUE INDEX prc_storage_reservations_disk_object_key_key ON public.prc_storage_reservations USING btree (disk, object_key)`
- `prc_storage_reservations_pkey`: `CREATE UNIQUE INDEX prc_storage_reservations_pkey ON public.prc_storage_reservations USING btree (id)`
- `prc_storage_reservations_upload_intent_id_key`: `CREATE UNIQUE INDEX prc_storage_reservations_upload_intent_id_key ON public.prc_storage_reservations USING btree (upload_intent_id)`

#### prc_target_health

Owner: Repositories/Procurement/Network; retention: authority.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text NOT NULL |
| availability | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| state_version | bigint / int8 | NO | bigint NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| recent_openings | bigint / int8 | NO | bigint NOT NULL |

Constraints:
- `prc_target_health_availability_check`: `CHECK ((availability = ANY (ARRAY['unknown'::text, 'available'::text, 'suspect'::text, 'unavailable'::text])))`
- `prc_target_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `prc_target_health_pkey`: `PRIMARY KEY (id)`
- `prc_target_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `prc_target_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `prc_target_health_state_version_check`: `CHECK ((state_version >= 1))`
- `prc_target_health_target_id_fkey`: `FOREIGN KEY (target_id) REFERENCES prc_source_targets(id)`
- `prc_target_health_target_id_key`: `UNIQUE (target_id)`

Indexes:
- `prc_target_health_pkey`: `CREATE UNIQUE INDEX prc_target_health_pkey ON public.prc_target_health USING btree (id)`
- `prc_target_health_target_id_key`: `CREATE UNIQUE INDEX prc_target_health_target_id_key ON public.prc_target_health USING btree (target_id)`

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

#### pxm_attempts

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| permit_id | uuid / uuid | NO | uuid NOT NULL |
| physical_attempt_id | uuid / uuid | NO | uuid NOT NULL |
| receipt_id | uuid / uuid | YES | uuid |
| wire_fingerprint | text / text | NO | text NOT NULL |
| status | text / text | NO | text NOT NULL |
| authorized_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| completed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| snapshot | jsonb / jsonb | NO | jsonb NOT NULL |
| dispatch_outcome | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |
| dispatch_refused_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| receipt_key | text / text | YES | text |
| receipt_digest | text / text | YES | text |
| result_digest | text / text | YES | text |
| result_summary | jsonb / jsonb | YES | jsonb |

Constraints:
- `pxm_attempts_check`: `CHECK (((dispatch_outcome = 'not_dispatched'::text) = (dispatch_refused_at IS NOT NULL)))`
- `pxm_attempts_dispatch_outcome_check`: `CHECK ((dispatch_outcome = ANY (ARRAY['unknown'::text, 'not_dispatched'::text])))`
- `pxm_attempts_pkey`: `PRIMARY KEY (id)`
- `pxm_attempts_receipt_digest_check`: `CHECK ((receipt_digest ~ '^[0-9a-f]{64}$'::text))`
- `pxm_attempts_receipt_key_check`: `CHECK ((receipt_key ~ '^[0-9a-f]{64}$'::text))`
- `pxm_attempts_result_digest_check`: `CHECK ((result_digest ~ '^[0-9a-f]{64}$'::text))`
- `pxm_attempts_snapshot_check`: `CHECK ((jsonb_typeof(snapshot) = 'object'::text))`
- `pxm_attempts_status_check`: `CHECK ((status = ANY (ARRAY['authorized'::text, 'completed'::text, 'unknown_after_authorization'::text])))`
- `pxm_attempts_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_attempts_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_attempts_tenant_key_operation_id_physical_attempt_id_key`: `UNIQUE (tenant_key, operation_id, physical_attempt_id)`
- `pxm_attempts_tenant_key_permit_id_operation_id_physical_at_fkey`: `FOREIGN KEY (tenant_key, permit_id, operation_id, physical_attempt_id) REFERENCES pxm_permits(tenant_key, id, operation_id, physical_attempt_id)`
- `pxm_attempts_tenant_key_receipt_id_key`: `UNIQUE (tenant_key, receipt_id)`
- `pxm_attempts_wire_fingerprint_check`: `CHECK ((wire_fingerprint ~ '^[0-9a-f]{64}$'::text))`
- `pxm_receipt_key_unique`: `UNIQUE (tenant_key, receipt_key)`
- `pxm_receipt_pair`: `CHECK ((((receipt_key IS NULL) AND (receipt_digest IS NULL) AND (result_digest IS NULL)) OR ((receipt_key IS NOT NULL) AND (receipt_digest IS NOT NULL) AND (result_digest IS NOT NULL))))`

Indexes:
- `pxm_attempt_unknown_idx`: `CREATE INDEX pxm_attempt_unknown_idx ON public.pxm_attempts USING btree (tenant_key, authorized_at, id) WHERE (status = 'unknown_after_authorization'::text)`
- `pxm_attempts_pkey`: `CREATE UNIQUE INDEX pxm_attempts_pkey ON public.pxm_attempts USING btree (id)`
- `pxm_attempts_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_attempts_tenant_key_id_key ON public.pxm_attempts USING btree (tenant_key, id)`
- `pxm_attempts_tenant_key_operation_id_physical_attempt_id_key`: `CREATE UNIQUE INDEX pxm_attempts_tenant_key_operation_id_physical_attempt_id_key ON public.pxm_attempts USING btree (tenant_key, operation_id, physical_attempt_id)`
- `pxm_attempts_tenant_key_receipt_id_key`: `CREATE UNIQUE INDEX pxm_attempts_tenant_key_receipt_id_key ON public.pxm_attempts USING btree (tenant_key, receipt_id)`
- `pxm_receipt_key_unique`: `CREATE UNIQUE INDEX pxm_receipt_key_unique ON public.pxm_attempts USING btree (tenant_key, receipt_key)`

#### pxm_audit_events

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| actor_type | text / text | NO | text NOT NULL |
| actor_id | text / text | NO | text NOT NULL |
| reason | text / text | NO | text NOT NULL |
| ability | text / text | NO | text NOT NULL |
| entity_type | text / text | NO | text NOT NULL |
| entity_id | uuid / uuid | NO | uuid NOT NULL |
| previous_revision | bigint / int8 | NO | bigint NOT NULL |
| revision | bigint / int8 | NO | bigint NOT NULL |
| idempotency_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| command_hash | text / text | NO | text NOT NULL |
| result | jsonb / jsonb | NO | jsonb NOT NULL |
| event_kind | text / text | NO | text DEFAULT 'mutation'::text NOT NULL |

Constraints:
- `pxm_audit_events_actor_id_check`: `CHECK (((octet_length(actor_id) >= 1) AND (octet_length(actor_id) <= 128)))`
- `pxm_audit_events_actor_type_check`: `CHECK ((actor_type ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`
- `pxm_audit_events_check`: `CHECK ((((event_kind = 'mutation'::text) AND (revision = (previous_revision + 1))) OR ((event_kind = 'read'::text) AND (revision = previous_revision) AND (revision >= 0))))`
- `pxm_audit_events_command_hash_check`: `CHECK ((command_hash ~ '^[0-9a-f]{64}$'::text))`
- `pxm_audit_events_entity_type_check`: `CHECK ((entity_type = ANY (ARRAY['endpoints'::text, 'routes'::text, 'route_profiles'::text, 'targets'::text, 'shared_route_grants'::text, 'policy_heads'::text, 'bindings'::text, 'budgets'::text, 'route_health'::text, 'route_target_health'::text, 'target_health'::text])))`
- `pxm_audit_events_event_kind_check`: `CHECK ((event_kind = ANY (ARRAY['mutation'::text, 'read'::text])))`
- `pxm_audit_events_idempotency_key_check`: `CHECK ((idempotency_key ~ '^[!-~]{1,128}$'::text))`
- `pxm_audit_events_pkey`: `PRIMARY KEY (id)`
- `pxm_audit_events_previous_revision_check`: `CHECK ((previous_revision >= 0))`
- `pxm_audit_events_reason_check`: `CHECK (((octet_length(reason) >= 1) AND (octet_length(reason) <= 512)))`
- `pxm_audit_events_result_check`: `CHECK ((jsonb_typeof(result) = 'object'::text))`
- `pxm_audit_events_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_audit_events_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_audit_events_tenant_key_idempotency_key_key`: `UNIQUE (tenant_key, idempotency_key)`

Indexes:
- `pxm_audit_entity_idx`: `CREATE INDEX pxm_audit_entity_idx ON public.pxm_audit_events USING btree (tenant_key, entity_type, entity_id, revision)`
- `pxm_audit_events_pkey`: `CREATE UNIQUE INDEX pxm_audit_events_pkey ON public.pxm_audit_events USING btree (id)`
- `pxm_audit_events_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_audit_events_tenant_key_id_key ON public.pxm_audit_events USING btree (tenant_key, id)`
- `pxm_audit_events_tenant_key_idempotency_key_key`: `CREATE UNIQUE INDEX pxm_audit_events_tenant_key_idempotency_key_key ON public.pxm_audit_events USING btree (tenant_key, idempotency_key)`

#### pxm_binding_revisions

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| binding_id | uuid / uuid | NO | uuid NOT NULL |
| revision | bigint / int8 | NO | bigint NOT NULL |
| policy_revision_id | uuid / uuid | NO | uuid NOT NULL |
| head_id | uuid / uuid | NO | uuid NOT NULL |
| profile_id | uuid / uuid | YES | uuid |
| policy_key | text / text | NO | text NOT NULL |
| retired | boolean / bool | NO | boolean NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `pxm_binding_revisions_pkey`: `PRIMARY KEY (id)`
- `pxm_binding_revisions_revision_check`: `CHECK ((revision >= 1))`
- `pxm_binding_revisions_tenant_key_binding_id_fkey`: `FOREIGN KEY (tenant_key, binding_id) REFERENCES pxm_bindings(tenant_key, id)`
- `pxm_binding_revisions_tenant_key_binding_id_revision_key`: `UNIQUE (tenant_key, binding_id, revision)`
- `pxm_binding_revisions_tenant_key_head_id_policy_revision_i_fkey`: `FOREIGN KEY (tenant_key, head_id, policy_revision_id) REFERENCES pxm_policy_revisions(tenant_key, head_id, id)`
- `pxm_binding_revisions_tenant_key_profile_id_fkey`: `FOREIGN KEY (tenant_key, profile_id) REFERENCES pxm_route_profiles(tenant_key, id)`

Indexes:
- `pxm_binding_revisions_pkey`: `CREATE UNIQUE INDEX pxm_binding_revisions_pkey ON public.pxm_binding_revisions USING btree (id)`
- `pxm_binding_revisions_tenant_key_binding_id_revision_key`: `CREATE UNIQUE INDEX pxm_binding_revisions_tenant_key_binding_id_revision_key ON public.pxm_binding_revisions USING btree (tenant_key, binding_id, revision)`

#### pxm_bindings

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| subject_type | text / text | NO | text NOT NULL |
| subject_id | text / text | NO | text NOT NULL |
| head_id | uuid / uuid | NO | uuid NOT NULL |
| profile_id | uuid / uuid | YES | uuid |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| panel_key | text / text | NO | text DEFAULT 'default'::text NOT NULL COLLATE pg_catalog."C" |
| slot_key | text / text | NO | text DEFAULT 'default'::text NOT NULL COLLATE pg_catalog."C" |
| policy_key | text / text | NO | text DEFAULT 'default'::text NOT NULL COLLATE pg_catalog."C" |

Constraints:
- `pxm_binding_scope_unique`: `UNIQUE (tenant_key, panel_key, subject_type, subject_id, slot_key)`
- `pxm_bindings_panel_key_check`: `CHECK ((panel_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_bindings_pkey`: `PRIMARY KEY (id)`
- `pxm_bindings_policy_key_check`: `CHECK ((policy_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_bindings_revision_check`: `CHECK ((revision >= 1))`
- `pxm_bindings_slot_key_check`: `CHECK ((slot_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_bindings_subject_id_check`: `CHECK (((octet_length(subject_id) >= 1) AND (octet_length(subject_id) <= 255)))`
- `pxm_bindings_subject_type_check`: `CHECK ((subject_type ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`
- `pxm_bindings_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_bindings_tenant_key_head_id_fkey`: `FOREIGN KEY (tenant_key, head_id) REFERENCES pxm_policy_heads(tenant_key, id) ON DELETE RESTRICT`
- `pxm_bindings_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_bindings_tenant_key_profile_id_fkey`: `FOREIGN KEY (tenant_key, profile_id) REFERENCES pxm_route_profiles(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_binding_scope_unique`: `CREATE UNIQUE INDEX pxm_binding_scope_unique ON public.pxm_bindings USING btree (tenant_key, panel_key, subject_type, subject_id, slot_key)`
- `pxm_bindings_pkey`: `CREATE UNIQUE INDEX pxm_bindings_pkey ON public.pxm_bindings USING btree (id)`
- `pxm_bindings_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_bindings_tenant_key_id_key ON public.pxm_bindings USING btree (tenant_key, id)`

#### pxm_budgets

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| scope_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| emission_interval_us | bigint / int8 | NO | bigint NOT NULL |
| burst | bigint / int8 | NO | bigint NOT NULL |
| max_inflight | bigint / int8 | NO | bigint NOT NULL |
| mandatory | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| tat | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |
| send_tat | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |
| inflight | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| hour_limit | bigint / int8 | YES | bigint |
| day_limit | bigint / int8 | YES | bigint |
| quota_policy_key | text / text | NO | text DEFAULT 'default'::text NOT NULL |
| traffic_class | text / text | YES | text |
| traffic_parent_id | uuid / uuid | YES | uuid |
| traffic_percent | integer / int4 | YES | integer |
| cooldown_until | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL |

Constraints:
- `pxm_budget_lock_idx`: `UNIQUE (tenant_key, scope_key)`
- `pxm_budgets_burst_check`: `CHECK ((burst > 0))`
- `pxm_budgets_check`: `CHECK ((((traffic_class IS NULL) AND (traffic_parent_id IS NULL) AND (traffic_percent IS NULL)) OR ((traffic_class IS NOT NULL) AND (traffic_percent IS NOT NULL) AND (traffic_class ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text) AND (traffic_parent_id IS NOT NULL) AND (traffic_parent_id <> id) AND ((traffic_percent >= 0) AND (traffic_percent <= 100)))))`
- `pxm_budgets_day_limit_check`: `CHECK ((day_limit >= 0))`
- `pxm_budgets_emission_interval_us_check`: `CHECK ((emission_interval_us > 0))`
- `pxm_budgets_hour_limit_check`: `CHECK ((hour_limit >= 0))`
- `pxm_budgets_inflight_check`: `CHECK ((inflight >= 0))`
- `pxm_budgets_max_inflight_check`: `CHECK ((max_inflight > 0))`
- `pxm_budgets_pkey`: `PRIMARY KEY (id)`
- `pxm_budgets_quota_policy_key_check`: `CHECK ((quota_policy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`
- `pxm_budgets_revision_check`: `CHECK ((revision >= 1))`
- `pxm_budgets_scope_key_check`: `CHECK ((scope_key ~ '^[a-z0-9][a-z0-9_.:-]{0,254}$'::text))`
- `pxm_budgets_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_budgets_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_budgets_tenant_key_traffic_parent_id_fkey`: `FOREIGN KEY (tenant_key, traffic_parent_id) REFERENCES pxm_budgets(tenant_key, id)`
- `pxm_cooldown_finite`: `CHECK ((cooldown_until <> 'infinity'::timestamp with time zone))`

Indexes:
- `pxm_budget_lock_idx`: `CREATE UNIQUE INDEX pxm_budget_lock_idx ON public.pxm_budgets USING btree (tenant_key, scope_key)`
- `pxm_budgets_pkey`: `CREATE UNIQUE INDEX pxm_budgets_pkey ON public.pxm_budgets USING btree (id)`
- `pxm_budgets_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_budgets_tenant_key_id_key ON public.pxm_budgets USING btree (tenant_key, id)`

#### pxm_catalog_command_receipts

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| audit_event_id | uuid / uuid | NO | uuid NOT NULL |
| idempotency_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| command_hash | text / text | NO | text NOT NULL |
| result | jsonb / jsonb | NO | jsonb NOT NULL |

Constraints:
- `pxm_catalog_command_receipts_command_hash_check`: `CHECK ((command_hash ~ '^[0-9a-f]{64}$'::text))`
- `pxm_catalog_command_receipts_pkey`: `PRIMARY KEY (id)`
- `pxm_catalog_command_receipts_result_check`: `CHECK ((jsonb_typeof(result) = 'object'::text))`
- `pxm_catalog_command_receipts_tenant_key_audit_event_id_fkey`: `FOREIGN KEY (tenant_key, audit_event_id) REFERENCES pxm_audit_events(tenant_key, id)`
- `pxm_catalog_command_receipts_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_catalog_command_receipts_tenant_key_idempotency_key_key`: `UNIQUE (tenant_key, idempotency_key)`

Indexes:
- `pxm_catalog_command_receipts_pkey`: `CREATE UNIQUE INDEX pxm_catalog_command_receipts_pkey ON public.pxm_catalog_command_receipts USING btree (id)`
- `pxm_catalog_command_receipts_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_catalog_command_receipts_tenant_key_id_key ON public.pxm_catalog_command_receipts USING btree (tenant_key, id)`
- `pxm_catalog_command_receipts_tenant_key_idempotency_key_key`: `CREATE UNIQUE INDEX pxm_catalog_command_receipts_tenant_key_idempotency_key_key ON public.pxm_catalog_command_receipts USING btree (tenant_key, idempotency_key)`

#### pxm_demand_scopes

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| demand_id | uuid / uuid | NO | uuid NOT NULL |
| budget_tenant_key | text / text | NO | text NOT NULL |
| budget_id | uuid / uuid | NO | uuid NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `pxm_demand_scopes_budget_tenant_key_budget_id_fkey`: `FOREIGN KEY (budget_tenant_key, budget_id) REFERENCES pxm_budgets(tenant_key, id)`
- `pxm_demand_scopes_check`: `CHECK (((budget_tenant_key = tenant_key) OR (budget_tenant_key = 'system'::text)))`
- `pxm_demand_scopes_pkey`: `PRIMARY KEY (id)`
- `pxm_demand_scopes_tenant_key_demand_id_budget_tenant_key_bu_key`: `UNIQUE (tenant_key, demand_id, budget_tenant_key, budget_id)`
- `pxm_demand_scopes_tenant_key_demand_id_fkey`: `FOREIGN KEY (tenant_key, demand_id) REFERENCES pxm_demands(tenant_key, id)`
- `pxm_scope_exact_guard`: `TRIGGER DEFERRABLE INITIALLY DEFERRED`

Indexes:
- `pxm_demand_scope_conflict_idx`: `CREATE INDEX pxm_demand_scope_conflict_idx ON public.pxm_demand_scopes USING btree (budget_id, demand_id)`
- `pxm_demand_scopes_pkey`: `CREATE UNIQUE INDEX pxm_demand_scopes_pkey ON public.pxm_demand_scopes USING btree (id)`
- `pxm_demand_scopes_tenant_key_demand_id_budget_tenant_key_bu_key`: `CREATE UNIQUE INDEX pxm_demand_scopes_tenant_key_demand_id_budget_tenant_key_bu_key ON public.pxm_demand_scopes USING btree (tenant_key, demand_id, budget_tenant_key, budget_id)`

#### pxm_demands

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| purpose | text / text | NO | text NOT NULL |
| state | text / text | NO | text DEFAULT 'active'::text NOT NULL |
| sequence | bigint / int8 | NO | bigint NOT NULL |
| cost | bigint / int8 | NO | bigint NOT NULL |
| deadline | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| physical_attempt_id | uuid / uuid | YES | uuid |
| wire_fingerprint | text / text | YES | text |
| intent_hash | text / text | YES | text |
| scope_hash | text / text | YES | text |
| expires_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| admitted_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| traffic_class | text / text | NO | text DEFAULT 'default'::text NOT NULL |

Constraints:
- `pxm_demand_identity_check`: `CHECK ((((physical_attempt_id IS NULL) AND (wire_fingerprint IS NULL) AND (intent_hash IS NULL) AND (scope_hash IS NULL) AND (expires_at IS NULL)) OR ((physical_attempt_id IS NOT NULL) AND (wire_fingerprint ~ '^[0-9a-f]{64}$'::text) AND (wire_fingerprint IS NOT NULL) AND (intent_hash ~ '^[0-9a-f]{64}$'::text) AND (intent_hash IS NOT NULL) AND (scope_hash ~ '^[0-9a-f]{64}$'::text) AND (scope_hash IS NOT NULL) AND (expires_at IS NOT NULL) AND isfinite(expires_at) AND (expires_at <= deadline))))`
- `pxm_demand_physical_unique`: `UNIQUE (tenant_key, operation_id, physical_attempt_id)`
- `pxm_demand_scope_exact_guard`: `TRIGGER DEFERRABLE INITIALLY DEFERRED`
- `pxm_demands_cost_check`: `CHECK ((cost > 0))`
- `pxm_demands_deadline_check`: `CHECK (isfinite(deadline))`
- `pxm_demands_pkey`: `PRIMARY KEY (id)`
- `pxm_demands_purpose_check`: `CHECK ((purpose = ANY (ARRAY['normal'::text, 'probe'::text])))`
- `pxm_demands_state_check`: `CHECK ((state = ANY (ARRAY['active'::text, 'admitted'::text, 'completed'::text, 'cancelled'::text, 'expired'::text])))`
- `pxm_demands_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_demands_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_demands_tenant_key_id_operation_id_target_id_key`: `UNIQUE (tenant_key, id, operation_id, target_id)`
- `pxm_demands_tenant_key_operation_id_profile_id_target_id_fkey`: `FOREIGN KEY (tenant_key, operation_id, profile_id, target_id) REFERENCES pxm_operations(tenant_key, id, profile_id, target_id)`
- `pxm_demands_traffic_class_check`: `CHECK ((traffic_class ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`

Indexes:
- `pxm_demand_deadline_idx`: `CREATE INDEX pxm_demand_deadline_idx ON public.pxm_demands USING btree (tenant_key, deadline, id) WHERE (state = ANY (ARRAY['active'::text, 'admitted'::text]))`
- `pxm_demand_fifo_active_idx`: `CREATE INDEX pxm_demand_fifo_active_idx ON public.pxm_demands USING btree (sequence, id) WHERE ((physical_attempt_id IS NOT NULL) AND (state = 'active'::text))`
- `pxm_demand_fifo_idx`: `CREATE INDEX pxm_demand_fifo_idx ON public.pxm_demands USING btree (tenant_key, profile_id, target_id, sequence) WHERE (state = 'active'::text)`
- `pxm_demand_physical_unique`: `CREATE UNIQUE INDEX pxm_demand_physical_unique ON public.pxm_demands USING btree (tenant_key, operation_id, physical_attempt_id)`
- `pxm_demands_pkey`: `CREATE UNIQUE INDEX pxm_demands_pkey ON public.pxm_demands USING btree (id)`
- `pxm_demands_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_demands_tenant_key_id_key ON public.pxm_demands USING btree (tenant_key, id)`
- `pxm_demands_tenant_key_id_operation_id_target_id_key`: `CREATE UNIQUE INDEX pxm_demands_tenant_key_id_operation_id_target_id_key ON public.pxm_demands USING btree (tenant_key, id, operation_id, target_id)`

#### pxm_encrypted_secrets

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| reference | text / text | NO | text NOT NULL |
| endpoint_id | uuid / uuid | NO | uuid NOT NULL |
| version | bigint / int8 | NO | bigint NOT NULL |
| ciphertext | text / text | NO | text NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `pxm_encrypted_secrets_ciphertext_check`: `CHECK ((octet_length(ciphertext) > 0))`
- `pxm_encrypted_secrets_pkey`: `PRIMARY KEY (tenant_key, reference)`
- `pxm_encrypted_secrets_reference_check`: `CHECK ((reference ~ '^[0-9a-f-]{36}$'::text))`
- `pxm_encrypted_secrets_tenant_key_endpoint_id_fkey`: `FOREIGN KEY (tenant_key, endpoint_id) REFERENCES pxm_endpoints(tenant_key, id) ON DELETE RESTRICT`
- `pxm_encrypted_secrets_tenant_key_endpoint_id_key`: `UNIQUE (tenant_key, endpoint_id)`
- `pxm_encrypted_secrets_version_check`: `CHECK ((version >= 1))`

Indexes:
- `pxm_encrypted_secrets_pkey`: `CREATE UNIQUE INDEX pxm_encrypted_secrets_pkey ON public.pxm_encrypted_secrets USING btree (tenant_key, reference)`
- `pxm_encrypted_secrets_tenant_key_endpoint_id_key`: `CREATE UNIQUE INDEX pxm_encrypted_secrets_tenant_key_endpoint_id_key ON public.pxm_encrypted_secrets USING btree (tenant_key, endpoint_id)`

#### pxm_endpoints

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| protocol | text / text | NO | text NOT NULL |
| host | text / text | NO | text NOT NULL |
| port | integer / int4 | NO | integer NOT NULL |
| credential_ref | text / text | YES | text |
| credential_version | bigint / int8 | YES | bigint |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_endpoints_check`: `CHECK (((credential_ref IS NULL) = (credential_version IS NULL)))`
- `pxm_endpoints_credential_ref_check`: `CHECK ((((octet_length(credential_ref) >= 1) AND (octet_length(credential_ref) <= 255)) AND (credential_ref !~ '[[:space:]]'::text)))`
- `pxm_endpoints_credential_version_check`: `CHECK ((credential_version >= 1))`
- `pxm_endpoints_host_check`: `CHECK ((((length(host) >= 1) AND (length(host) <= 253)) AND (host = lower(host)) AND (host !~ '[[:space:]]'::text)))`
- `pxm_endpoints_pkey`: `PRIMARY KEY (id)`
- `pxm_endpoints_port_check`: `CHECK (((port >= 1) AND (port <= 65535)))`
- `pxm_endpoints_protocol_check`: `CHECK ((protocol = ANY (ARRAY['http'::text, 'https'::text, 'socks5'::text, 'socks5h'::text])))`
- `pxm_endpoints_revision_check`: `CHECK ((revision >= 1))`
- `pxm_endpoints_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_endpoints_tenant_key_id_key`: `UNIQUE (tenant_key, id)`

Indexes:
- `pxm_endpoints_pkey`: `CREATE UNIQUE INDEX pxm_endpoints_pkey ON public.pxm_endpoints USING btree (id)`
- `pxm_endpoints_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_endpoints_tenant_key_id_key ON public.pxm_endpoints USING btree (tenant_key, id)`

#### pxm_imported_health_samples

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| tenant_key | text / text | NO | text NOT NULL |
| observation_id | uuid / uuid | NO | uuid NOT NULL |
| attempt_id | uuid / uuid | NO | uuid NOT NULL |
| application_key | text / text | NO | text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| signal | text / text | NO | text NOT NULL |
| independence_group | text / text | YES | text |
| reducer_allowed | boolean / bool | NO | boolean NOT NULL |
| observed_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `pxm_imported_health_samples_health_epoch_check`: `CHECK ((health_epoch > 0))`
- `pxm_imported_health_samples_pkey`: `PRIMARY KEY (tenant_key, observation_id, application_key)`
- `pxm_imported_health_samples_signal_check`: `CHECK ((signal = ANY (ARRAY['success'::text, 'failure'::text, 'maintenance'::text])))`

Indexes:
- `pxm_imported_health_samples_pkey`: `CREATE UNIQUE INDEX pxm_imported_health_samples_pkey ON public.pxm_imported_health_samples USING btree (tenant_key, observation_id, application_key)`
- `pxm_imported_window`: `CREATE INDEX pxm_imported_window ON public.pxm_imported_health_samples USING btree (tenant_key, application_key, health_epoch, observed_at)`

#### pxm_observation_applications

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| observation_id | uuid / uuid | NO | uuid NOT NULL |
| application_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| health_epoch | bigint / int8 | NO | bigint NOT NULL |
| reducer_allowed | boolean / bool | NO | boolean DEFAULT true NOT NULL |

Constraints:
- `pxm_observation_applications_application_key_check`: `CHECK ((application_key ~ '^[a-z0-9][a-z0-9_.:-]{0,254}$'::text))`
- `pxm_observation_applications_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `pxm_observation_applications_pkey`: `PRIMARY KEY (id)`
- `pxm_observation_applications_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_observation_applications_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_observation_applications_tenant_key_observation_id_appl_key`: `UNIQUE (tenant_key, observation_id, application_key)`
- `pxm_observation_applications_tenant_key_observation_id_fkey`: `FOREIGN KEY (tenant_key, observation_id) REFERENCES pxm_observations(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_observation_applications_pkey`: `CREATE UNIQUE INDEX pxm_observation_applications_pkey ON public.pxm_observation_applications USING btree (id)`
- `pxm_observation_applications_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_observation_applications_tenant_key_id_key ON public.pxm_observation_applications USING btree (tenant_key, id)`
- `pxm_observation_applications_tenant_key_observation_id_appl_key`: `CREATE UNIQUE INDEX pxm_observation_applications_tenant_key_observation_id_appl_key ON public.pxm_observation_applications USING btree (tenant_key, observation_id, application_key)`

#### pxm_observations

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| attempt_id | uuid / uuid | NO | uuid NOT NULL |
| receipt_id | uuid / uuid | NO | uuid NOT NULL |
| classification | text / text | NO | text NOT NULL |
| evidence | jsonb / jsonb | NO | jsonb NOT NULL |
| observed_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |

Constraints:
- `pxm_observations_classification_check`: `CHECK (((octet_length(classification) >= 1) AND (octet_length(classification) <= 64)))`
- `pxm_observations_evidence_check`: `CHECK ((jsonb_typeof(evidence) = 'object'::text))`
- `pxm_observations_pkey`: `PRIMARY KEY (id)`
- `pxm_observations_tenant_key_attempt_id_fkey`: `FOREIGN KEY (tenant_key, attempt_id) REFERENCES pxm_attempts(tenant_key, id) ON DELETE RESTRICT`
- `pxm_observations_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_observations_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_observations_tenant_key_receipt_id_key`: `UNIQUE (tenant_key, receipt_id)`

Indexes:
- `pxm_observation_attempt_idx`: `CREATE INDEX pxm_observation_attempt_idx ON public.pxm_observations USING btree (tenant_key, attempt_id)`
- `pxm_observation_kind_once`: `CREATE UNIQUE INDEX pxm_observation_kind_once ON public.pxm_observations USING btree (tenant_key, attempt_id, classification)`
- `pxm_observations_pkey`: `CREATE UNIQUE INDEX pxm_observations_pkey ON public.pxm_observations USING btree (id)`
- `pxm_observations_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_observations_tenant_key_id_key ON public.pxm_observations USING btree (tenant_key, id)`
- `pxm_observations_tenant_key_receipt_id_key`: `CREATE UNIQUE INDEX pxm_observations_tenant_key_receipt_id_key ON public.pxm_observations USING btree (tenant_key, receipt_id)`

#### pxm_operations

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| owner_type | text / text | NO | text NOT NULL |
| owner_id | text / text | NO | text NOT NULL |
| owner_token | text / text | NO | text NOT NULL |
| owner_generation | bigint / int8 | NO | bigint NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| status | text / text | NO | text DEFAULT 'active'::text NOT NULL |
| deadline | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| revoked_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| connect_timeout_ms | integer / int4 | NO | integer DEFAULT 1000 NOT NULL |
| request_timeout_ms | integer / int4 | NO | integer DEFAULT 10000 NOT NULL |
| max_physical_attempts | integer / int4 | NO | integer NOT NULL |
| authorized_attempts | integer / int4 | NO | integer DEFAULT 0 NOT NULL |
| external_operation_key | text / text | YES | text |
| parent_attempt_id | uuid / uuid | YES | uuid |
| intent_hash | text / text | YES | text |
| purpose | text / text | NO | text DEFAULT 'ordinary'::text NOT NULL |
| probe_token | uuid / uuid | YES | uuid |

Constraints:
- `pxm_operation_correlation_check`: `CHECK (((external_operation_key IS NULL) OR (((octet_length(external_operation_key) >= 1) AND (octet_length(external_operation_key) <= 255)) AND (external_operation_key !~ '[[:space:]]'::text))))`
- `pxm_operation_intent_check`: `CHECK (((intent_hash IS NULL) OR (intent_hash ~ '^[0-9a-f]{64}$'::text)))`
- `pxm_operation_probe_token_check`: `CHECK (((purpose = 'probe'::text) = (probe_token IS NOT NULL)))`
- `pxm_operations_check`: `CHECK (((request_timeout_ms > 0) AND (request_timeout_ms >= connect_timeout_ms)))`
- `pxm_operations_check1`: `CHECK (((authorized_attempts >= 0) AND (authorized_attempts <= max_physical_attempts)))`
- `pxm_operations_connect_timeout_ms_check`: `CHECK ((connect_timeout_ms > 0))`
- `pxm_operations_deadline_check`: `CHECK (isfinite(deadline))`
- `pxm_operations_max_physical_attempts_check`: `CHECK ((max_physical_attempts > 0))`
- `pxm_operations_owner_generation_check`: `CHECK ((owner_generation >= 1))`
- `pxm_operations_owner_id_check`: `CHECK (((octet_length(owner_id) >= 1) AND (octet_length(owner_id) <= 255)))`
- `pxm_operations_owner_token_check`: `CHECK (((octet_length(owner_token) >= 1) AND (octet_length(owner_token) <= 255)))`
- `pxm_operations_owner_type_check`: `CHECK (((octet_length(owner_type) >= 1) AND (octet_length(owner_type) <= 64)))`
- `pxm_operations_pkey`: `PRIMARY KEY (id)`
- `pxm_operations_purpose_check`: `CHECK ((purpose = ANY (ARRAY['ordinary'::text, 'probe'::text, 'diagnostic'::text])))`
- `pxm_operations_revision_check`: `CHECK ((revision >= 1))`
- `pxm_operations_status_check`: `CHECK ((status = ANY (ARRAY['active'::text, 'completed'::text, 'cancelled'::text, 'expired'::text])))`
- `pxm_operations_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_operations_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_operations_tenant_key_id_profile_id_target_id_key`: `UNIQUE (tenant_key, id, profile_id, target_id)`
- `pxm_operations_tenant_key_profile_id_fkey`: `FOREIGN KEY (tenant_key, profile_id) REFERENCES pxm_route_profiles(tenant_key, id) ON DELETE RESTRICT`
- `pxm_operations_tenant_key_target_id_fkey`: `FOREIGN KEY (tenant_key, target_id) REFERENCES pxm_targets(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_operation_deadline_idx`: `CREATE INDEX pxm_operation_deadline_idx ON public.pxm_operations USING btree (tenant_key, deadline, id) WHERE (status = 'active'::text)`
- `pxm_operations_pkey`: `CREATE UNIQUE INDEX pxm_operations_pkey ON public.pxm_operations USING btree (id)`
- `pxm_operations_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_operations_tenant_key_id_key ON public.pxm_operations USING btree (tenant_key, id)`
- `pxm_operations_tenant_key_id_profile_id_target_id_key`: `CREATE UNIQUE INDEX pxm_operations_tenant_key_id_profile_id_target_id_key ON public.pxm_operations USING btree (tenant_key, id, profile_id, target_id)`

#### pxm_permits

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| demand_id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| physical_attempt_id | uuid / uuid | NO | uuid NOT NULL |
| wire_fingerprint | text / text | NO | text NOT NULL |
| state | text / text | NO | text DEFAULT 'reserved'::text NOT NULL |
| cost | bigint / int8 | NO | bigint NOT NULL |
| not_before | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| expires_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| authorized_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| completed_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| claim_id | uuid / uuid | YES | uuid |
| reservation_intent | text / text | YES | text |
| reservation_evidence | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| reservation_limits | jsonb / jsonb | NO | jsonb DEFAULT '{}'::jsonb NOT NULL |
| lease_released_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_permit_artifact_identity`: `UNIQUE (tenant_key, id, operation_id)`
- `pxm_permit_reservation_intent_check`: `CHECK (((reservation_intent IS NULL) OR (reservation_intent ~ '^[0-9a-f]{64}$'::text)))`
- `pxm_permits_check`: `CHECK ((isfinite(not_before) AND isfinite(expires_at) AND (expires_at > not_before)))`
- `pxm_permits_check1`: `CHECK ((((state = ANY (ARRAY['reserved'::text, 'cancelled_before_send'::text, 'expired_before_send'::text])) AND (authorized_at IS NULL) AND (claim_id IS NULL)) OR ((state = ANY (ARRAY['authorized'::text, 'completed'::text, 'unknown_after_authorization'::text])) AND (authorized_at IS NOT NULL) AND (claim_id IS NOT NULL))))`
- `pxm_permits_check2`: `CHECK (((state = 'completed'::text) = (completed_at IS NOT NULL)))`
- `pxm_permits_cost_check`: `CHECK ((cost > 0))`
- `pxm_permits_pkey`: `PRIMARY KEY (id)`
- `pxm_permits_state_check`: `CHECK ((state = ANY (ARRAY['reserved'::text, 'authorized'::text, 'completed'::text, 'unknown_after_authorization'::text, 'cancelled_before_send'::text, 'expired_before_send'::text])))`
- `pxm_permits_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_permits_tenant_key_claim_id_key`: `UNIQUE (tenant_key, claim_id)`
- `pxm_permits_tenant_key_demand_id_key`: `UNIQUE (tenant_key, demand_id)`
- `pxm_permits_tenant_key_demand_id_operation_id_target_id_fkey`: `FOREIGN KEY (tenant_key, demand_id, operation_id, target_id) REFERENCES pxm_demands(tenant_key, id, operation_id, target_id)`
- `pxm_permits_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_permits_tenant_key_id_operation_id_physical_attempt_id_key`: `UNIQUE (tenant_key, id, operation_id, physical_attempt_id)`
- `pxm_permits_tenant_key_operation_id_fkey`: `FOREIGN KEY (tenant_key, operation_id) REFERENCES pxm_operations(tenant_key, id) ON DELETE RESTRICT`
- `pxm_permits_tenant_key_operation_id_physical_attempt_id_key`: `UNIQUE (tenant_key, operation_id, physical_attempt_id)`
- `pxm_permits_tenant_key_route_id_fkey`: `FOREIGN KEY (tenant_key, route_id) REFERENCES pxm_routes(tenant_key, id) ON DELETE RESTRICT`
- `pxm_permits_tenant_key_target_id_fkey`: `FOREIGN KEY (tenant_key, target_id) REFERENCES pxm_targets(tenant_key, id) ON DELETE RESTRICT`
- `pxm_permits_wire_fingerprint_check`: `CHECK ((wire_fingerprint ~ '^[0-9a-f]{64}$'::text))`

Indexes:
- `pxm_permit_artifact_identity`: `CREATE UNIQUE INDEX pxm_permit_artifact_identity ON public.pxm_permits USING btree (tenant_key, id, operation_id)`
- `pxm_permit_due_idx`: `CREATE INDEX pxm_permit_due_idx ON public.pxm_permits USING btree (tenant_key, expires_at, id) WHERE (state = ANY (ARRAY['reserved'::text, 'authorized'::text]))`
- `pxm_permit_operation_idx`: `CREATE INDEX pxm_permit_operation_idx ON public.pxm_permits USING btree (tenant_key, operation_id, id)`
- `pxm_permits_pkey`: `CREATE UNIQUE INDEX pxm_permits_pkey ON public.pxm_permits USING btree (id)`
- `pxm_permits_tenant_key_claim_id_key`: `CREATE UNIQUE INDEX pxm_permits_tenant_key_claim_id_key ON public.pxm_permits USING btree (tenant_key, claim_id)`
- `pxm_permits_tenant_key_demand_id_key`: `CREATE UNIQUE INDEX pxm_permits_tenant_key_demand_id_key ON public.pxm_permits USING btree (tenant_key, demand_id)`
- `pxm_permits_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_permits_tenant_key_id_key ON public.pxm_permits USING btree (tenant_key, id)`
- `pxm_permits_tenant_key_id_operation_id_physical_attempt_id_key`: `CREATE UNIQUE INDEX pxm_permits_tenant_key_id_operation_id_physical_attempt_id_key ON public.pxm_permits USING btree (tenant_key, id, operation_id, physical_attempt_id)`
- `pxm_permits_tenant_key_operation_id_physical_attempt_id_key`: `CREATE UNIQUE INDEX pxm_permits_tenant_key_operation_id_physical_attempt_id_key ON public.pxm_permits USING btree (tenant_key, operation_id, physical_attempt_id)`
- `pxm_recovery_permit_idx`: `CREATE INDEX pxm_recovery_permit_idx ON public.pxm_permits USING btree (operation_id, expires_at) WHERE (lease_released_at IS NULL)`

#### pxm_policy_heads

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| scope_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| current_revision_id | uuid / uuid | YES | uuid |

Constraints:
- `pxm_policy_current_fk`: `FOREIGN KEY (tenant_key, id, current_revision_id) REFERENCES pxm_policy_revisions(tenant_key, head_id, id) DEFERRABLE INITIALLY DEFERRED`
- `pxm_policy_heads_pkey`: `PRIMARY KEY (id)`
- `pxm_policy_heads_revision_check`: `CHECK ((revision >= 1))`
- `pxm_policy_heads_scope_key_check`: `CHECK ((scope_key ~ '^[a-z0-9][a-z0-9_.:-]{0,254}$'::text))`
- `pxm_policy_heads_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_policy_heads_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_policy_heads_tenant_key_scope_key_key`: `UNIQUE (tenant_key, scope_key)`

Indexes:
- `pxm_policy_heads_pkey`: `CREATE UNIQUE INDEX pxm_policy_heads_pkey ON public.pxm_policy_heads USING btree (id)`
- `pxm_policy_heads_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_policy_heads_tenant_key_id_key ON public.pxm_policy_heads USING btree (tenant_key, id)`
- `pxm_policy_heads_tenant_key_scope_key_key`: `CREATE UNIQUE INDEX pxm_policy_heads_tenant_key_scope_key_key ON public.pxm_policy_heads USING btree (tenant_key, scope_key)`

#### pxm_policy_revisions

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| head_id | uuid / uuid | NO | uuid NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| definition | jsonb / jsonb | NO | jsonb NOT NULL |

Constraints:
- `pxm_policy_revisions_definition_check`: `CHECK ((jsonb_typeof(definition) = 'object'::text))`
- `pxm_policy_revisions_pkey`: `PRIMARY KEY (id)`
- `pxm_policy_revisions_revision_check`: `CHECK ((revision >= 1))`
- `pxm_policy_revisions_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_policy_revisions_tenant_key_head_id_fkey`: `FOREIGN KEY (tenant_key, head_id) REFERENCES pxm_policy_heads(tenant_key, id) ON DELETE RESTRICT`
- `pxm_policy_revisions_tenant_key_head_id_id_key`: `UNIQUE (tenant_key, head_id, id)`
- `pxm_policy_revisions_tenant_key_head_id_revision_key`: `UNIQUE (tenant_key, head_id, revision)`
- `pxm_policy_revisions_tenant_key_id_key`: `UNIQUE (tenant_key, id)`

Indexes:
- `pxm_policy_revisions_pkey`: `CREATE UNIQUE INDEX pxm_policy_revisions_pkey ON public.pxm_policy_revisions USING btree (id)`
- `pxm_policy_revisions_tenant_key_head_id_id_key`: `CREATE UNIQUE INDEX pxm_policy_revisions_tenant_key_head_id_id_key ON public.pxm_policy_revisions USING btree (tenant_key, head_id, id)`
- `pxm_policy_revisions_tenant_key_head_id_revision_key`: `CREATE UNIQUE INDEX pxm_policy_revisions_tenant_key_head_id_revision_key ON public.pxm_policy_revisions USING btree (tenant_key, head_id, revision)`
- `pxm_policy_revisions_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_policy_revisions_tenant_key_id_key ON public.pxm_policy_revisions USING btree (tenant_key, id)`

#### pxm_probe_claims

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| epochs | jsonb / jsonb | NO | jsonb NOT NULL |
| purpose | text / text | NO | text DEFAULT 'probe'::text NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| expires_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| physical_attempt_id | uuid / uuid | YES | uuid |

Constraints:
- `pxm_probe_claims_check`: `CHECK ((isfinite(created_at) AND isfinite(expires_at) AND (expires_at > created_at) AND (expires_at <= (created_at + '00:05:00'::interval))))`
- `pxm_probe_claims_epochs_check`: `CHECK (((jsonb_typeof(epochs) = 'object'::text) AND (epochs <> '{}'::jsonb)))`
- `pxm_probe_claims_pkey`: `PRIMARY KEY (id)`
- `pxm_probe_claims_purpose_check`: `CHECK ((purpose = 'probe'::text))`
- `pxm_probe_claims_tenant_key_operation_id_fkey`: `FOREIGN KEY (tenant_key, operation_id) REFERENCES pxm_operations(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_probe_claims_pkey`: `CREATE UNIQUE INDEX pxm_probe_claims_pkey ON public.pxm_probe_claims USING btree (id)`

#### pxm_profile_health

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| state_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| recent_openings | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_profile_health_check`: `CHECK (((probe_token IS NULL) = (probe_until IS NULL)))`
- `pxm_profile_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `pxm_profile_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `pxm_profile_health_pkey`: `PRIMARY KEY (id)`
- `pxm_profile_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `pxm_profile_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `pxm_profile_health_state_version_check`: `CHECK ((state_version >= 1))`
- `pxm_profile_health_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_profile_health_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_profile_health_tenant_key_profile_id_fkey`: `FOREIGN KEY (tenant_key, profile_id) REFERENCES pxm_route_profiles(tenant_key, id) ON DELETE RESTRICT`
- `pxm_profile_health_tenant_key_profile_id_key`: `UNIQUE (tenant_key, profile_id)`

Indexes:
- `pxm_profile_health_pkey`: `CREATE UNIQUE INDEX pxm_profile_health_pkey ON public.pxm_profile_health USING btree (id)`
- `pxm_profile_health_probe_idx`: `CREATE INDEX pxm_profile_health_probe_idx ON public.pxm_profile_health USING btree (tenant_key, open_until, id) WHERE (state = ANY (ARRAY['open'::text, 'half_open'::text]))`
- `pxm_profile_health_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_profile_health_tenant_key_id_key ON public.pxm_profile_health USING btree (tenant_key, id)`
- `pxm_profile_health_tenant_key_profile_id_key`: `CREATE UNIQUE INDEX pxm_profile_health_tenant_key_profile_id_key ON public.pxm_profile_health USING btree (tenant_key, profile_id)`

#### pxm_quota_reservations

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| permit_id | uuid / uuid | NO | uuid NOT NULL |
| budget_tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| budget_id | uuid / uuid | NO | uuid NOT NULL |
| window_id | uuid / uuid | NO | uuid NOT NULL |
| cost | bigint / int8 | NO | bigint NOT NULL |
| state | text / text | NO | text NOT NULL |

Constraints:
- `pxm_quota_reservations_budget_tenant_key_window_id_budget__fkey`: `FOREIGN KEY (budget_tenant_key, window_id, budget_id) REFERENCES pxm_quota_windows(tenant_key, id, budget_id)`
- `pxm_quota_reservations_check`: `CHECK (((budget_tenant_key = tenant_key) OR (budget_tenant_key = 'system'::text)))`
- `pxm_quota_reservations_cost_check`: `CHECK ((cost > 0))`
- `pxm_quota_reservations_pkey`: `PRIMARY KEY (id)`
- `pxm_quota_reservations_state_check`: `CHECK ((state = ANY (ARRAY['reserved'::text, 'consumed'::text, 'released'::text])))`
- `pxm_quota_reservations_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_quota_reservations_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_quota_reservations_tenant_key_permit_id_fkey`: `FOREIGN KEY (tenant_key, permit_id) REFERENCES pxm_permits(tenant_key, id)`
- `pxm_quota_reservations_tenant_key_permit_id_window_id_key`: `UNIQUE (tenant_key, permit_id, window_id)`

Indexes:
- `pxm_quota_reservations_pkey`: `CREATE UNIQUE INDEX pxm_quota_reservations_pkey ON public.pxm_quota_reservations USING btree (id)`
- `pxm_quota_reservations_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_quota_reservations_tenant_key_id_key ON public.pxm_quota_reservations USING btree (tenant_key, id)`
- `pxm_quota_reservations_tenant_key_permit_id_window_id_key`: `CREATE UNIQUE INDEX pxm_quota_reservations_tenant_key_permit_id_window_id_key ON public.pxm_quota_reservations USING btree (tenant_key, permit_id, window_id)`

#### pxm_quota_windows

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| budget_id | uuid / uuid | NO | uuid NOT NULL |
| starts_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| ends_at | timestamp with time zone / timestamptz | NO | timestamp with time zone NOT NULL |
| quota_limit | bigint / int8 | NO | bigint NOT NULL |
| reserved | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| spent | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| window_kind | text / text | NO | text DEFAULT 'hour'::text NOT NULL |
| policy_key | text / text | NO | text DEFAULT 'default'::text NOT NULL |

Constraints:
- `pxm_quota_windows_check`: `CHECK ((isfinite(starts_at) AND isfinite(ends_at) AND (ends_at > starts_at)))`
- `pxm_quota_windows_check1`: `CHECK (((starts_at = (date_trunc(window_kind, (starts_at AT TIME ZONE 'UTC'::text)) AT TIME ZONE 'UTC'::text)) AND (ends_at = (starts_at + CASE window_kind WHEN 'hour'::text THEN '01:00:00'::interval ELSE '24:00:00'::interval END))))`
- `pxm_quota_windows_pkey`: `PRIMARY KEY (id)`
- `pxm_quota_windows_policy_key_check`: `CHECK ((policy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`
- `pxm_quota_windows_quota_limit_check`: `CHECK ((quota_limit >= 0))`
- `pxm_quota_windows_reserved_check`: `CHECK ((reserved >= 0))`
- `pxm_quota_windows_spent_check`: `CHECK ((spent >= 0))`
- `pxm_quota_windows_tenant_key_budget_id_fkey`: `FOREIGN KEY (tenant_key, budget_id) REFERENCES pxm_budgets(tenant_key, id) ON DELETE RESTRICT`
- `pxm_quota_windows_tenant_key_budget_id_window_kind_starts_a_key`: `UNIQUE (tenant_key, budget_id, window_kind, starts_at, policy_key)`
- `pxm_quota_windows_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_quota_windows_tenant_key_id_budget_id_key`: `UNIQUE (tenant_key, id, budget_id)`
- `pxm_quota_windows_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_quota_windows_window_kind_check`: `CHECK ((window_kind = ANY (ARRAY['hour'::text, 'day'::text])))`

Indexes:
- `pxm_quota_windows_pkey`: `CREATE UNIQUE INDEX pxm_quota_windows_pkey ON public.pxm_quota_windows USING btree (id)`
- `pxm_quota_windows_tenant_key_budget_id_window_kind_starts_a_key`: `CREATE UNIQUE INDEX pxm_quota_windows_tenant_key_budget_id_window_kind_starts_a_key ON public.pxm_quota_windows USING btree (tenant_key, budget_id, window_kind, starts_at, policy_key)`
- `pxm_quota_windows_tenant_key_id_budget_id_key`: `CREATE UNIQUE INDEX pxm_quota_windows_tenant_key_id_budget_id_key ON public.pxm_quota_windows USING btree (tenant_key, id, budget_id)`
- `pxm_quota_windows_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_quota_windows_tenant_key_id_key ON public.pxm_quota_windows USING btree (tenant_key, id)`

#### pxm_reconcile_cursors

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| after_id | uuid / uuid | YES | uuid |
| lease_token | uuid / uuid | YES | uuid |
| lease_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_reconcile_cursors_check`: `CHECK (((lease_token IS NULL) = (lease_until IS NULL)))`
- `pxm_reconcile_cursors_lease_until_check`: `CHECK (isfinite(lease_until))`
- `pxm_reconcile_cursors_pkey`: `PRIMARY KEY (id)`
- `pxm_reconcile_cursors_tenant_key_key`: `UNIQUE (tenant_key)`

Indexes:
- `pxm_reconcile_cursors_pkey`: `CREATE UNIQUE INDEX pxm_reconcile_cursors_pkey ON public.pxm_reconcile_cursors USING btree (id)`
- `pxm_reconcile_cursors_tenant_key_key`: `CREATE UNIQUE INDEX pxm_reconcile_cursors_tenant_key_key ON public.pxm_reconcile_cursors USING btree (tenant_key)`

#### pxm_reservations

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| permit_id | uuid / uuid | NO | uuid NOT NULL |
| budget_id | uuid / uuid | NO | uuid NOT NULL |
| quota_window_id | uuid / uuid | YES | uuid |
| cost | bigint / int8 | NO | bigint NOT NULL |
| state | text / text | NO | text NOT NULL |
| budget_tenant_key | text / text | NO | text NOT NULL |

Constraints:
- `pxm_reservation_budget_owner_fk`: `FOREIGN KEY (budget_tenant_key, budget_id) REFERENCES pxm_budgets(tenant_key, id)`
- `pxm_reservation_owner_check`: `CHECK (((budget_tenant_key = tenant_key) OR (budget_tenant_key = 'system'::text)))`
- `pxm_reservation_window_owner_fk`: `FOREIGN KEY (budget_tenant_key, quota_window_id, budget_id) REFERENCES pxm_quota_windows(tenant_key, id, budget_id)`
- `pxm_reservations_cost_check`: `CHECK ((cost > 0))`
- `pxm_reservations_pkey`: `PRIMARY KEY (id)`
- `pxm_reservations_state_check`: `CHECK ((state = ANY (ARRAY['reserved'::text, 'spent'::text, 'released'::text])))`
- `pxm_reservations_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_reservations_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_reservations_tenant_key_permit_id_budget_id_key`: `UNIQUE (tenant_key, permit_id, budget_id)`
- `pxm_reservations_tenant_key_permit_id_fkey`: `FOREIGN KEY (tenant_key, permit_id) REFERENCES pxm_permits(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_reservation_budget_idx`: `CREATE INDEX pxm_reservation_budget_idx ON public.pxm_reservations USING btree (tenant_key, budget_id, state)`
- `pxm_reservations_pkey`: `CREATE UNIQUE INDEX pxm_reservations_pkey ON public.pxm_reservations USING btree (id)`
- `pxm_reservations_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_reservations_tenant_key_id_key ON public.pxm_reservations USING btree (tenant_key, id)`
- `pxm_reservations_tenant_key_permit_id_budget_id_key`: `CREATE UNIQUE INDEX pxm_reservations_tenant_key_permit_id_budget_id_key ON public.pxm_reservations USING btree (tenant_key, permit_id, budget_id)`

#### pxm_route_health

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| state_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| recent_openings | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| credential_endpoint_id | uuid / uuid | YES | uuid |
| credential_version | bigint / int8 | YES | bigint |

Constraints:
- `pxm_credential_hold_identity`: `CHECK ((((credential_endpoint_id IS NULL) = (credential_version IS NULL)) AND (credential_version >= 1)))`
- `pxm_route_health_check`: `CHECK (((probe_token IS NULL) = (probe_until IS NULL)))`
- `pxm_route_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `pxm_route_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `pxm_route_health_pkey`: `PRIMARY KEY (id)`
- `pxm_route_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `pxm_route_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `pxm_route_health_state_version_check`: `CHECK ((state_version >= 1))`
- `pxm_route_health_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_route_health_tenant_key_credential_endpoint_id_fkey`: `FOREIGN KEY (tenant_key, credential_endpoint_id) REFERENCES pxm_endpoints(tenant_key, id) ON DELETE RESTRICT`
- `pxm_route_health_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_route_health_tenant_key_route_id_fkey`: `FOREIGN KEY (tenant_key, route_id) REFERENCES pxm_routes(tenant_key, id) ON DELETE RESTRICT`
- `pxm_route_health_tenant_key_route_id_key`: `UNIQUE (tenant_key, route_id)`

Indexes:
- `pxm_route_health_pkey`: `CREATE UNIQUE INDEX pxm_route_health_pkey ON public.pxm_route_health USING btree (id)`
- `pxm_route_health_probe_idx`: `CREATE INDEX pxm_route_health_probe_idx ON public.pxm_route_health USING btree (tenant_key, open_until, id) WHERE (state = ANY (ARRAY['open'::text, 'half_open'::text]))`
- `pxm_route_health_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_route_health_tenant_key_id_key ON public.pxm_route_health USING btree (tenant_key, id)`
- `pxm_route_health_tenant_key_route_id_key`: `CREATE UNIQUE INDEX pxm_route_health_tenant_key_route_id_key ON public.pxm_route_health USING btree (tenant_key, route_id)`

#### pxm_route_profile_members

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| profile_id | uuid / uuid | NO | uuid NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| position | integer / int4 | NO | integer NOT NULL |
| route_owner_tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| shared_grant_id | uuid / uuid | YES | uuid |

Constraints:
- `pxm_route_profile_members_check`: `CHECK ((((route_owner_tenant_key = tenant_key) AND (shared_grant_id IS NULL)) OR ((route_owner_tenant_key <> tenant_key) AND (shared_grant_id IS NOT NULL))))`
- `pxm_route_profile_members_pkey`: `PRIMARY KEY (id)`
- `pxm_route_profile_members_position_check`: `CHECK (("position" >= 0))`
- `pxm_route_profile_members_route_owner_tenant_key_route_id_fkey`: `FOREIGN KEY (route_owner_tenant_key, route_id) REFERENCES pxm_routes(tenant_key, id)`
- `pxm_route_profile_members_route_owner_tenant_key_tenant_ke_fkey`: `FOREIGN KEY (route_owner_tenant_key, tenant_key, route_id, shared_grant_id) REFERENCES pxm_shared_route_grants(tenant_key, consumer_tenant_key, route_id, id)`
- `pxm_route_profile_members_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_route_profile_members_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_route_profile_members_tenant_key_profile_id_fkey`: `FOREIGN KEY (tenant_key, profile_id) REFERENCES pxm_route_profiles(tenant_key, id) ON DELETE RESTRICT`
- `pxm_route_profile_members_tenant_key_profile_id_position_key`: `UNIQUE (tenant_key, profile_id, "position")`
- `pxm_route_profile_members_tenant_key_profile_id_route_id_key`: `UNIQUE (tenant_key, profile_id, route_id)`

Indexes:
- `pxm_member_route_idx`: `CREATE INDEX pxm_member_route_idx ON public.pxm_route_profile_members USING btree (tenant_key, route_id)`
- `pxm_route_profile_members_pkey`: `CREATE UNIQUE INDEX pxm_route_profile_members_pkey ON public.pxm_route_profile_members USING btree (id)`
- `pxm_route_profile_members_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_route_profile_members_tenant_key_id_key ON public.pxm_route_profile_members USING btree (tenant_key, id)`
- `pxm_route_profile_members_tenant_key_profile_id_position_key`: `CREATE UNIQUE INDEX pxm_route_profile_members_tenant_key_profile_id_position_key ON public.pxm_route_profile_members USING btree (tenant_key, profile_id, "position")`
- `pxm_route_profile_members_tenant_key_profile_id_route_id_key`: `CREATE UNIQUE INDEX pxm_route_profile_members_tenant_key_profile_id_route_id_key ON public.pxm_route_profile_members USING btree (tenant_key, profile_id, route_id)`

#### pxm_route_profiles

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| profile_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| strategy_key | text / text | NO | text NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_route_profiles_pkey`: `PRIMARY KEY (id)`
- `pxm_route_profiles_profile_key_check`: `CHECK ((profile_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_route_profiles_revision_check`: `CHECK ((revision >= 1))`
- `pxm_route_profiles_strategy_key_check`: `CHECK ((strategy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'::text))`
- `pxm_route_profiles_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_route_profiles_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_route_profiles_tenant_key_profile_key_key`: `UNIQUE (tenant_key, profile_key)`

Indexes:
- `pxm_route_profiles_pkey`: `CREATE UNIQUE INDEX pxm_route_profiles_pkey ON public.pxm_route_profiles USING btree (id)`
- `pxm_route_profiles_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_route_profiles_tenant_key_id_key ON public.pxm_route_profiles USING btree (tenant_key, id)`
- `pxm_route_profiles_tenant_key_profile_key_key`: `CREATE UNIQUE INDEX pxm_route_profiles_tenant_key_profile_key_key ON public.pxm_route_profiles USING btree (tenant_key, profile_key)`

#### pxm_route_target_health

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| state_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| recent_openings | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_route_target_health_check`: `CHECK (((probe_token IS NULL) = (probe_until IS NULL)))`
- `pxm_route_target_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `pxm_route_target_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `pxm_route_target_health_pkey`: `PRIMARY KEY (id)`
- `pxm_route_target_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `pxm_route_target_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `pxm_route_target_health_state_version_check`: `CHECK ((state_version >= 1))`
- `pxm_route_target_health_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_route_target_health_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_route_target_health_tenant_key_route_id_fkey`: `FOREIGN KEY (tenant_key, route_id) REFERENCES pxm_routes(tenant_key, id) ON DELETE RESTRICT`
- `pxm_route_target_health_tenant_key_route_id_target_id_key`: `UNIQUE (tenant_key, route_id, target_id)`
- `pxm_route_target_health_tenant_key_target_id_fkey`: `FOREIGN KEY (tenant_key, target_id) REFERENCES pxm_targets(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_route_target_health_pkey`: `CREATE UNIQUE INDEX pxm_route_target_health_pkey ON public.pxm_route_target_health USING btree (id)`
- `pxm_route_target_health_probe_idx`: `CREATE INDEX pxm_route_target_health_probe_idx ON public.pxm_route_target_health USING btree (tenant_key, open_until, id) WHERE (state = ANY (ARRAY['open'::text, 'half_open'::text]))`
- `pxm_route_target_health_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_route_target_health_tenant_key_id_key ON public.pxm_route_target_health USING btree (tenant_key, id)`
- `pxm_route_target_health_tenant_key_route_id_target_id_key`: `CREATE UNIQUE INDEX pxm_route_target_health_tenant_key_route_id_target_id_key ON public.pxm_route_target_health USING btree (tenant_key, route_id, target_id)`
- `pxm_route_target_target_idx`: `CREATE INDEX pxm_route_target_target_idx ON public.pxm_route_target_health USING btree (tenant_key, target_id, route_id)`

#### pxm_routes

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| route_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| kind | text / text | NO | text NOT NULL |
| endpoint_id | uuid / uuid | YES | uuid |
| enabled | boolean / bool | NO | boolean DEFAULT true NOT NULL |
| egress_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| conservative_pool | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| independence_group | text / text | YES | text |
| shared_grant | boolean / bool | NO | boolean DEFAULT false NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_routes_check`: `CHECK ((((kind = 'proxy'::text) AND (endpoint_id IS NOT NULL)) OR ((kind = 'direct'::text) AND (endpoint_id IS NULL))))`
- `pxm_routes_check1`: `CHECK (((egress_key <> route_key) AND ((independence_group IS NULL) OR (egress_key <> independence_group))))`
- `pxm_routes_egress_key_check`: `CHECK ((egress_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_routes_independence_group_check`: `CHECK ((independence_group ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_routes_pkey`: `PRIMARY KEY (id)`
- `pxm_routes_revision_check`: `CHECK ((revision >= 1))`
- `pxm_routes_route_key_check`: `CHECK ((route_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_routes_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_routes_tenant_key_endpoint_id_fkey`: `FOREIGN KEY (tenant_key, endpoint_id) REFERENCES pxm_endpoints(tenant_key, id) ON DELETE RESTRICT`
- `pxm_routes_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_routes_tenant_key_route_key_key`: `UNIQUE (tenant_key, route_key)`

Indexes:
- `pxm_route_endpoint_idx`: `CREATE INDEX pxm_route_endpoint_idx ON public.pxm_routes USING btree (tenant_key, endpoint_id)`
- `pxm_routes_pkey`: `CREATE UNIQUE INDEX pxm_routes_pkey ON public.pxm_routes USING btree (id)`
- `pxm_routes_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_routes_tenant_key_id_key ON public.pxm_routes USING btree (tenant_key, id)`
- `pxm_routes_tenant_key_route_key_key`: `CREATE UNIQUE INDEX pxm_routes_tenant_key_route_key_key ON public.pxm_routes USING btree (tenant_key, route_key)`

#### pxm_shared_route_grants

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| consumer_tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| route_id | uuid / uuid | NO | uuid NOT NULL |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |

Constraints:
- `pxm_shared_route_grants_check`: `CHECK ((tenant_key <> consumer_tenant_key))`
- `pxm_shared_route_grants_consumer_tenant_key_check`: `CHECK ((consumer_tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_shared_route_grants_pkey`: `PRIMARY KEY (id)`
- `pxm_shared_route_grants_revision_check`: `CHECK ((revision >= 1))`
- `pxm_shared_route_grants_tenant_key_consumer_tenant_key_rout_key`: `UNIQUE (tenant_key, consumer_tenant_key, route_id, id)`
- `pxm_shared_route_grants_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_shared_route_grants_tenant_key_route_id_fkey`: `FOREIGN KEY (tenant_key, route_id) REFERENCES pxm_routes(tenant_key, id) ON DELETE RESTRICT`

Indexes:
- `pxm_grant_active_idx`: `CREATE UNIQUE INDEX pxm_grant_active_idx ON public.pxm_shared_route_grants USING btree (tenant_key, route_id, consumer_tenant_key) WHERE (retired_at IS NULL)`
- `pxm_grant_consumer_idx`: `CREATE INDEX pxm_grant_consumer_idx ON public.pxm_shared_route_grants USING btree (consumer_tenant_key, route_id) WHERE (retired_at IS NULL)`
- `pxm_shared_route_grants_pkey`: `CREATE UNIQUE INDEX pxm_shared_route_grants_pkey ON public.pxm_shared_route_grants USING btree (id)`
- `pxm_shared_route_grants_tenant_key_consumer_tenant_key_rout_key`: `CREATE UNIQUE INDEX pxm_shared_route_grants_tenant_key_consumer_tenant_key_rout_key ON public.pxm_shared_route_grants USING btree (tenant_key, consumer_tenant_key, route_id, id)`
- `pxm_shared_route_grants_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_shared_route_grants_tenant_key_id_key ON public.pxm_shared_route_grants USING btree (tenant_key, id)`

#### pxm_target_health

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| target_id | uuid / uuid | NO | uuid NOT NULL |
| state | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |
| health_epoch | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| state_version | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| consecutive_failures | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| recent_openings | bigint / int8 | NO | bigint DEFAULT 0 NOT NULL |
| reason_code | text / text | YES | text |
| last_success_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| last_failure_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| open_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| probe_token | uuid / uuid | YES | uuid |
| probe_until | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| stable_since | timestamp with time zone / timestamptz | YES | timestamp with time zone |
| availability | text / text | NO | text DEFAULT 'unknown'::text NOT NULL |

Constraints:
- `pxm_target_health_availability_check`: `CHECK ((availability = ANY (ARRAY['unknown'::text, 'available'::text, 'suspect'::text, 'unavailable'::text])))`
- `pxm_target_health_check`: `CHECK (((probe_token IS NULL) = (probe_until IS NULL)))`
- `pxm_target_health_consecutive_failures_check`: `CHECK ((consecutive_failures >= 0))`
- `pxm_target_health_health_epoch_check`: `CHECK ((health_epoch >= 1))`
- `pxm_target_health_pkey`: `PRIMARY KEY (id)`
- `pxm_target_health_recent_openings_check`: `CHECK ((recent_openings >= 0))`
- `pxm_target_health_state_check`: `CHECK ((state = ANY (ARRAY['unknown'::text, 'closed'::text, 'open'::text, 'half_open'::text])))`
- `pxm_target_health_state_version_check`: `CHECK ((state_version >= 1))`
- `pxm_target_health_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_target_health_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_target_health_tenant_key_target_id_fkey`: `FOREIGN KEY (tenant_key, target_id) REFERENCES pxm_targets(tenant_key, id) ON DELETE RESTRICT`
- `pxm_target_health_tenant_key_target_id_key`: `UNIQUE (tenant_key, target_id)`

Indexes:
- `pxm_target_health_pkey`: `CREATE UNIQUE INDEX pxm_target_health_pkey ON public.pxm_target_health USING btree (id)`
- `pxm_target_health_probe_idx`: `CREATE INDEX pxm_target_health_probe_idx ON public.pxm_target_health USING btree (tenant_key, open_until, id) WHERE (state = ANY (ARRAY['open'::text, 'half_open'::text]))`
- `pxm_target_health_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_target_health_tenant_key_id_key ON public.pxm_target_health USING btree (tenant_key, id)`
- `pxm_target_health_tenant_key_target_id_key`: `CREATE UNIQUE INDEX pxm_target_health_tenant_key_target_id_key ON public.pxm_target_health USING btree (tenant_key, target_id)`

#### pxm_targets

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid DEFAULT gen_random_uuid() NOT NULL |
| tenant_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| updated_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| target_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| canonical_origin | text / text | NO | text NOT NULL |
| destination_policy_key | text / text | NO | text NOT NULL COLLATE pg_catalog."C" |
| revision | bigint / int8 | NO | bigint DEFAULT 1 NOT NULL |
| retired_at | timestamp with time zone / timestamptz | YES | timestamp with time zone |

Constraints:
- `pxm_targets_canonical_origin_check`: `CHECK (((octet_length(canonical_origin) <= 280) AND (canonical_origin = lower(canonical_origin)) AND (canonical_origin ~ '^https?://[^/@[:space:]]+:[1-9][0-9]{0,4}$'::text) AND ((("substring"(canonical_origin, ':([0-9]+)$'::text))::integer >= 1) AND (("substring"(canonical_origin, ':([0-9]+)$'::text))::integer <= 65535))))`
- `pxm_targets_destination_policy_key_check`: `CHECK ((destination_policy_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_targets_pkey`: `PRIMARY KEY (id)`
- `pxm_targets_revision_check`: `CHECK ((revision >= 1))`
- `pxm_targets_target_key_check`: `CHECK ((target_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_targets_tenant_key_check`: `CHECK ((tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'::text))`
- `pxm_targets_tenant_key_id_key`: `UNIQUE (tenant_key, id)`
- `pxm_targets_tenant_key_target_key_key`: `UNIQUE (tenant_key, target_key)`

Indexes:
- `pxm_targets_pkey`: `CREATE UNIQUE INDEX pxm_targets_pkey ON public.pxm_targets USING btree (id)`
- `pxm_targets_tenant_key_id_key`: `CREATE UNIQUE INDEX pxm_targets_tenant_key_id_key ON public.pxm_targets USING btree (tenant_key, id)`
- `pxm_targets_tenant_key_target_key_key`: `CREATE UNIQUE INDEX pxm_targets_tenant_key_target_key_key ON public.pxm_targets USING btree (tenant_key, target_key)`

#### pxm_temporary_artifacts

Owner: Proxyma; retention: proxyma.

| Column | PostgreSQL type | Nullable | Exact column definition |
| --- | --- | --- | --- |
| id | uuid / uuid | NO | uuid NOT NULL |
| tenant_key | text / text | NO | text NOT NULL |
| operation_id | uuid / uuid | NO | uuid NOT NULL |
| permit_id | uuid / uuid | NO | uuid NOT NULL |
| ownership_token | text / text | NO | text NOT NULL |
| created_at | timestamp with time zone / timestamptz | NO | timestamp with time zone DEFAULT clock_timestamp() NOT NULL |
| delete_pending | boolean / bool | NO | boolean DEFAULT false NOT NULL |

Constraints:
- `pxm_temporary_artifacts_ownership_token_check`: `CHECK ((ownership_token ~ '^[0-9a-f]{64}$'::text))`
- `pxm_temporary_artifacts_pkey`: `PRIMARY KEY (id)`
- `pxm_temporary_artifacts_tenant_key_permit_id_operation_id_fkey`: `FOREIGN KEY (tenant_key, permit_id, operation_id) REFERENCES pxm_permits(tenant_key, id, operation_id)`

Indexes:
- `pxm_temporary_artifacts_pkey`: `CREATE UNIQUE INDEX pxm_temporary_artifacts_pkey ON public.pxm_temporary_artifacts USING btree (id)`

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

## Передача P1.6 → P1.7–P1.12

P1.6 устанавливает 19 Process carriers в dependency order bp-ddl-core (control/handler authority,
lanes, runs, incidents, реальные crawl subjects, intents до work FK, attempts, groups/members,
run links, transitions, outbox, daemon heartbeats, request/observation facts) и initial
head/cycle authority в исходных CREATE `prc_procurements`/`prc_documents`. Inventory помечает их
installed (`current_item` P1.6); network, projection, documents и AI carriers остаются будущими.
Writers, models и проверяемые DB инварианты описаны в [Process core](process-core.md).

`SchemaSupportedStages` повторяет stage CHECK и owner tuple; тест сверяет его с реальным
`pg_get_constraintdef`. Unsupported stage отвергается до INSERT. Partial indexes due/lease/claim/
fairness/provider recovery и handler fault budget присутствуют с точными predicates. Runtime
primitives, admission, recovery и consumers ещё не активны: P1.7–P1.11 используют этот baseline без
повторного DDL, а изменения schema остаются правкой исходных baseline files с двумя clean install.


## P2.6 provider ownership amendment (partial candidate)

The clean baseline now declares the owner-authorized provider branch below. The full P2.6
consumer/schema cutover and its two isolated installs remain unaccepted; this note is not an
installed-schema or activation claim. Earlier schema snapshots above predate this amendment.

- `prc_source_targets.platform_id` is nullable and keeps its real platform FK. New nullable
  `provider_key` is validated by `[a-z][a-z0-9_.:-]{0,95}`. An exclusive CHECK requires exactly
  one of platform_id/provider_key. Platform and provider target keys have separate unique pairs;
  provider target keys carry `provider:<provider_key>:<endpoint_key>`.
- `prc_outbound_operations.provider_key` is nullable, grammar-checked, and cannot coexist with
  platform_id. A provider operation must bind its exact provider control and system ancestor.
  Target/owner mismatch is rejected by the common network authority. No ProcessWork or financial
  identity is fabricated for a legacy provider request.
- `prc_control_scopes.scope_kind` includes `provider`, with `system → provider:<key>` topology.
  `prc_network_demands.traffic_class` includes `provider`; demand purpose remains ordinary/probe/diagnostic.
- Origin identity, shared budgets, existing TAT/send_tat/not_before debt and overrides remain
  independent of the provider/platform owner branch. A sync only retires its own catalog.

`ProviderOwnershipTest` exercises the real PostgreSQL CHECK/unique failures and canonical
provider controls. Full schema/reset manifests and all remaining old binding/health removals
are still required on the completed P2.6 candidate.
