# P6.1 Deliverable — карта вхождений мёртвых префиксов и column-drift

**Дата:** 2026-07-10  
**Инвентарь:** `grep -rnoE '\b(doc_|est_|ai_|crw_|sys_|plt_)[a-z_]+' docs/specs/v2 docs/03_Dev docs-private | grep -v '/history/'` = 208 строк (== universum §1 Context/D29). Column-drift (`source_number`/`source_guid`/`source_status`) — отдельный греп, 13 строк.

**Правило классификации — D30+D32 (frozen, per-occurrence):**
- токен ∈ {`doc_prepare`,`ai_screening`,`ai_extraction`,`ai_extractions`} → **N/A** (решение D32: не имена таблиц, см. §0 ниже).
- токен ∈ KEEP-ALWAYS (15 v1-only токенов) → **KEEP-v1** безусловно.
- иначе токен ∈ RENAME-map (29 mappable) → **RENAME→канон**.
- Проверено вручную (см. §5): ни разу RENAME-map токен не встретился в явном v1-контексте (`перенос из v1`/`аналог v1 <token>`/arrow-source и т.п.) — во всём корпусе v1-контекст сопровождает ТОЛЬКО KEEP-ALWAYS-токены. Поэтому per-occurrence правило свернулось к чистому membership-тесту без единого исключения; это зафиксировано как проверенный факт, не как срезание угла.

## 0. N/A — 4 токена вне области действия D30 (Решение D32)

D30 писал правило RENAME/KEEP только для ИМЁН ТАБЛИЦ (`Phase Context`: «208 вхождений мёртвых префиксов **таблиц**»). Регулярка `\b(doc_|est_|ai_|crw_|sys_|plt_)[a-z_]+` шире: она также ловит literal-строки **имён очередей Horizon** и гипотетические имена несозданных таблиц:

| Токен | Вхождений | Природа | Решение D32 |
|:--|--:|:--|:--|
| `doc_prepare` | 4 | Живое имя очереди `PrepareDocumentJob::onQueue('doc_prepare')` (`app/Jobs/…:29`, `config/horizon.php:219`) | N/A — не ссылка на таблицу |
| `ai_screening` | 4 | Живое имя очереди `ScreeningJob::onQueue('ai_screening')` (`ScreeningJob.php:37`, `config/horizon.php:233`) | N/A — не ссылка на таблицу |
| `ai_extraction` | 5 | Живое имя очереди `EstimateExtractionJob::onQueue('ai_extraction')` (`EstimateExtractionJob.php:42`, `config/horizon.php:252`, `config/ai.php:236`) | N/A — не ссылка на таблицу |
| `ai_extractions` | 1 | Гипотетическое имя НЕ созданной таблицы (ADR-0004:23 — «НЕ создаётся заранее»), не в RENAME-map | N/A — не ссылка на существующую таблицу |

**Обоснование:** три первых токена — действующие имена живых очередей Horizon (не таблиц), KEEP-ALWAYS/RENAME-map их не содержат (это queue-strings, не table-strings). Четвёртый — гипотетическое имя несозданной таблицы, ни RENAME (нет цели), ни KEEP-v1 (не v1-таблица) не подходят. Третья frozen-категория **N/A** («не ссылка на таблицу, не трогать») — легальный остаток грепа наравне с KEEP-v1. Гейт G-under P6.2 переформулирован на residue-set = KEEP-v1 ∪ N/A (K=28 + N=14 = 42 строки префиксов).

---

## 1. Префиксы таблиц (208 строк)

| файл:строка | токен | RENAME→X / KEEP-v1 / NC | причина |
|:--|:--|:--|:--|
| `docs/specs/v2/05-admin-observability.md:14` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:28` | `ai_prompts` | RENAME->prc_ai_prompts | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:29` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:29` | `ai_providers` | RENAME->prc_ai_providers | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:42` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:42` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/05-admin-observability.md:56` | `sys_logs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/02-crawling.md:61` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:68` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:70` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:86` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:95` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:100` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:100` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:114` | `crw_crawlers` | RENAME->prc_crawlers | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:114` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:123` | `crw_proxies` | RENAME->prc_proxies | v2-дрейф (RENAME-map) |
| `docs/specs/v2/02-crawling.md:168` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:15` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:35` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:42` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:49` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:49` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:58` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_sections` | RENAME->prc_estimate_sections | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_items` | RENAME->prc_estimate_items | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_resources` | RENAME->prc_estimate_resources | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_totals` | RENAME->prc_estimate_totals | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:77` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:79` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:89` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:89` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:98` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/03-document-pipeline.md:109` | `plt_document_types` | RENAME->prc_document_types | v2-дрейф (RENAME-map) |
| `docs-private/open-follow-ups.md:7` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs-private/open-follow-ups.md:9` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs-private/open-follow-ups.md:41` | `crw_crawlers` | RENAME->prc_crawlers | v2-дрейф (RENAME-map) |
| `docs/03_Dev/adr/0004-jsonb-for-non-estimate-results.md:23` | `ai_extractions` | N/A | гипотетическая НЕсозданная таблица (ADR-0004, §23: «НЕ создаётся»), не ссылка на таблицу |
| `docs/03_Dev/getting-started.md:25` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/03_Dev/adr/0005-protected-prompts.md:15` | `plt_prompt_rules` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/03_Dev/document-pipeline.md:55` | `doc_prepare` | N/A | живое имя очереди Horizon (PrepareDocumentJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/document-pipeline.md:56` | `ai_screening` | N/A | живое имя очереди Horizon (ScreeningJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/document-pipeline.md:57` | `ai_extraction` | N/A | живое имя очереди Horizon (EstimateExtractionJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/document-pipeline.md:154` | `doc_prepare` | N/A | живое имя очереди Horizon (PrepareDocumentJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/document-pipeline.md:154` | `ai_screening` | N/A | живое имя очереди Horizon (ScreeningJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/document-pipeline.md:154` | `ai_extraction` | N/A | живое имя очереди Horizon (EstimateExtractionJob::onQueue), не ссылка на таблицу |
| `docs/specs/v2/06-roadmap.md:19` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:19` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:19` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:25` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:33` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:50` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/06-roadmap.md:55` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:8` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:10` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:50` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:53` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:59` | `ai_prompts` | RENAME->prc_ai_prompts | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:64` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:78` | `ai_dataset_examples` | RENAME->prc_ai_dataset_examples | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:79` | `ai_eval_runs` | RENAME->prc_ai_eval_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:79` | `ai_eval_results` | RENAME->prc_ai_eval_results | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:80` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:84` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/04-llm-providers.md:85` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs-private/crawler-gotchas.md:37` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs-private/crawler-gotchas.md:37` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs-private/crawler-gotchas.md:47` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/03_Dev/docker/index.md:14` | `doc_prepare` | N/A | живое имя очереди Horizon (PrepareDocumentJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/docker/index.md:14` | `ai_screening` | N/A | живое имя очереди Horizon (ScreeningJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/docker/index.md:14` | `ai_extraction` | N/A | живое имя очереди Horizon (EstimateExtractionJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/runbooks/horizon-backlog.md:11` | `doc_prepare` | N/A | живое имя очереди Horizon (PrepareDocumentJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/runbooks/horizon-backlog.md:11` | `ai_screening` | N/A | живое имя очереди Horizon (ScreeningJob::onQueue), не ссылка на таблицу |
| `docs/03_Dev/runbooks/horizon-backlog.md:11` | `ai_extraction` | N/A | живое имя очереди Horizon (EstimateExtractionJob::onQueue), не ссылка на таблицу |
| `docs-private/prompt-engineering-notes.md:10` | `plt_prompt_rules` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/03_Dev/runbooks/stuck-analysis.md:13` | `ai_extraction` | N/A | живое имя очереди Horizon (EstimateExtractionJob::onQueue), не ссылка на таблицу |
| `docs/specs/v2/07-carryover-from-v1.md:15` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:16` | `plt_crawler_runs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:16` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:17` | `plt_crawler_runs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:21` | `ai_prompts` | RENAME->prc_ai_prompts | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:23` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:24` | `crw_proxies` | RENAME->prc_proxies | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:34` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:36` | `est_totals` | RENAME->prc_estimate_totals | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:36` | `est_resources` | RENAME->prc_estimate_resources | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:37` | `plt_regions` | RENAME->prc_regions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:37` | `crw_ignored_items` | RENAME->prc_ignored_items | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:37` | `crw_daily_stats` | RENAME->prc_daily_stats | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:37` | `plt_crawler_analytics` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:40` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:53` | `plt_document_analysis_logs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:53` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:53` | `sys_operation_logs` | RENAME->prc_operation_logs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:54` | `plt_platform_proxies` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:54` | `crw_proxies` | RENAME->prc_proxies | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:55` | `est_items` | RENAME->prc_estimate_items | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:117` | `crw_proxies` | RENAME->prc_proxies | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:157` | `plt_crawler_links` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:157` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/07-carryover-from-v1.md:157` | `plt_prompt_rules` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:159` | `sys_logs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:159` | `plt_document_analysis` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:159` | `plt_crawlers` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/07-carryover-from-v1.md:159` | `sys_proxies` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs-private/risk-register.md:10` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:12` | `plt_document_analysis` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs-private/risk-register.md:12` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:14` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:15` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:16` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:17` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:18` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:18` | `plt_crawler_link_attachments` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs-private/risk-register.md:20` | `sys_operation_logs` | RENAME->prc_operation_logs | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:26` | `plt_parsing_items` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs-private/risk-register.md:28` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:28` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:29` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:29` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:40` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:40` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:54` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs-private/risk-register.md:54` | `sys_operation_logs` | RENAME->prc_operation_logs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/08-estimate-extraction.md:75` | `plt_document_analysis` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/08-estimate-extraction.md:75` | `plt_document_analysis_responses` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/08-estimate-extraction.md:75` | `plt_document_analysis_logs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/08-estimate-extraction.md:77` | `plt_document_analysis_responses` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:10` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:10` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:10` | `sys_operation_logs` | RENAME->prc_operation_logs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:10` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:15` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_providers` | RENAME->prc_ai_providers | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_prompts` | RENAME->prc_ai_prompts | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_dataset_examples` | RENAME->prc_ai_dataset_examples | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_eval_runs` | RENAME->prc_ai_eval_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:29` | `ai_eval_results` | RENAME->prc_ai_eval_results | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:35` | `plt_platforms` | RENAME->prc_platforms | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:38` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:41` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:44` | `plt_document_types` | RENAME->prc_document_types | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:45` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:47` | `plt_regions` | RENAME->prc_regions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:48` | `plt_platform_regions` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:90` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:105` | `plt_crawler_links` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:178` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:194` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:197` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:204` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:205` | `plt_document_types` | RENAME->prc_document_types | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:206` | `plt_document_analysis` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:208` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:209` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:213` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:216` | `est_sections` | RENAME->prc_estimate_sections | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:219` | `est_items` | RENAME->prc_estimate_items | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:222` | `est_resources` | RENAME->prc_estimate_resources | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:225` | `est_totals` | RENAME->prc_estimate_totals | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:230` | `ai_providers` | RENAME->prc_ai_providers | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:231` | `ai_routes` | RENAME->prc_ai_routes | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:231` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:231` | `plt_document_types` | RENAME->prc_document_types | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:232` | `ai_prompts` | RENAME->prc_ai_prompts | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:232` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:233` | `ai_dataset_examples` | RENAME->prc_ai_dataset_examples | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:234` | `ai_eval_runs` | RENAME->prc_ai_eval_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:235` | `ai_eval_results` | RENAME->prc_ai_eval_results | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:239` | `crw_crawlers` | RENAME->prc_crawlers | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:242` | `crw_runs` | RENAME->prc_crawler_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:245` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:248` | `crw_proxies` | RENAME->prc_proxies | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:248` | `crw_proxy_checks` | RENAME->prc_proxy_checks | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:249` | `sys_proxies` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:251` | `crw_ignore_rules` | RENAME->prc_ignore_rules | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:254` | `crw_ignored_items` | RENAME->prc_ignored_items | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:254` | `plt_crawler_ignore_links` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:255` | `crw_ignore_rules` | RENAME->prc_ignore_rules | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:257` | `crw_daily_stats` | RENAME->prc_daily_stats | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:257` | `plt_crawler_analytics` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:258` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:258` | `ai_llm_calls` | RENAME->prc_ai_llm_calls | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:262` | `sys_operation_logs` | RENAME->prc_operation_logs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:262` | `sys_logs` | KEEP-v1 | v1-имя (KEEP-ALWAYS, D30) |
| `docs/specs/v2/01-database.md:268` | `crw_fetches` | RENAME->prc_fetches | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:274` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:284` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:290` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:290` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:291` | `doc_documents` | RENAME->prc_documents | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:291` | `doc_versions` | RENAME->prc_document_versions | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:294` | `plt_platforms` | RENAME->prc_platforms | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:294` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:294` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:295` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:297` | `ai_dataset_examples` | RENAME->prc_ai_dataset_examples | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:297` | `est_estimates` | RENAME->prc_estimates | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:297` | `ai_eval_runs` | RENAME->prc_ai_eval_runs | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:298` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:299` | `ai_analyses` | RENAME->prc_ai_analyses | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:305` | `plt_platforms` | RENAME->prc_platforms | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:305` | `plt_registries` | RENAME->prc_registries | v2-дрейф (RENAME-map) |
| `docs/specs/v2/01-database.md:305` | `plt_status_mappings` | RENAME->prc_status_mappings | v2-дрейф (RENAME-map) |

---

## 2. Колонки (13 строк, column-drift D29)

| файл:строка | токен | RENAME→X / KEEP-v1 | причина |
|:--|:--|:--|:--|
| `docs/specs/v2/02-crawling.md:9` | `source_number` | RENAME->registry_number | column-drift D5/D19 |
| `docs/specs/v2/02-crawling.md:66` | `source_number` | RENAME->registry_number | column-drift D5/D19 |
| `docs/specs/v2/02-crawling.md:78` | `source_number` | RENAME->registry_number | column-drift D5/D19 |
| `docs/specs/v2/02-crawling.md:106` | `source_status` | RENAME->source_stage | column-drift D5/D18 |
| `docs/specs/v2/02-crawling.md:166` | `source_guid` | RENAME->registry_guid | column-drift D5 |
| `docs/specs/v2/01-database.md:15` | `source_status` | RENAME->source_stage | column-drift D5/D18 |
| `docs/specs/v2/01-database.md:42` | `source_status` | RENAME->source_stage | column-drift D5/D18 |
| `docs/specs/v2/01-database.md:91` | `source_number` | RENAME->registry_number | column-drift D5/D19 |
| `docs/specs/v2/01-database.md:96` | `source_status` | RENAME->source_stage | column-drift D5/D18 |
| `docs/specs/v2/01-database.md:115` | `source_guid` | RENAME->registry_guid | column-drift D5 |
| `docs/specs/v2/01-database.md:178` | `source_number` | KEEP-v1 | протокольный номер prc_protocols (D20 исключение) |
| `docs/specs/v2/01-database.md:255` | `source_number` | RENAME->registry_number | column-drift D5/D19 |
| `docs/specs/v2/01-database.md:316` | `source_guid` | RENAME->registry_guid | column-drift D5 |

---

## 3. Gate-числа

- **Префиксы:** total = 208. R = 166, K = 28, N = 14. R+K+N = 208.
- **Колонки:** total = 13. R = 12, K = 1 (`01-database.md:178`, протокольный `source_number`, D20). R+K = 13.
- **Общий универсум:** 208 + 13 = 221. R = 178, K = 29, N = 14.
- **Валидация Implementation Rules:** `grep -rnoE … | wc -l` = 208 = число строк §1. `grep -rnE 'source_number|source_guid|source_status' … | wc -l` = 13 = число строк §2 (посчитано по строкам файла, не по occurrence — совпадает с форматом гейта P6.2 Validation, который тоже не `-o`).

---

## 4. Реконсиляция с P1 §5

```
$ grep -cE '\b(doc_|est_|ai_|crw_|sys_|plt_)[a-z_]+' docs/03_Dev/database.md
0
```
Подтверждено: `docs/03_Dev/database.md` = 0 вхождений мёртвых префиксов. «79» из `findings/P1-actual-names.md` §5 относились к `docs/specs/v2/01-database.md` (в этой карте — 79 строк §1, файл `01-database.md`), не к `docs/03_Dev/database.md`. Расхождение P1 §5 объясняется опиской в имени файла, не ошибкой подсчёта.

---

## 5. Проверка arrow-rule / v1-контекста для RENAME-map токенов

Вручную сверены все файлы с явными v1-маркерами построчно против списка §1. Результат: КАЖДОЕ вхождение, помеченное KEEP-v1, — токен из KEEP-ALWAYS. Ни один RENAME-map токен не встретился в позиции v1-source (arrow-source/«перенос из v1 `<token>`») — только KEEP-ALWAYS токены цитируются как v1-имена. Единственный файл с обеих сторон arrow — `07-carryover-from-v1.md`: arrow-targets (`crw_runs`, `ai_prompts`, `ai_analyses`, `crw_proxies`, `est_totals`, `est_resources`, `plt_regions`, `crw_ignored_items`, `crw_daily_stats`, `doc_versions`, `ai_llm_calls`, `sys_operation_logs`, `est_items`) — все RENAME; arrow-sources (`plt_crawler_runs`, `plt_crawler_analytics`, `plt_document_analysis_logs`, `plt_platform_proxies`, `plt_crawler_links`, `plt_prompt_rules`, `sys_logs`, `plt_document_analysis`, `plt_crawlers`, `sys_proxies`) — все KEEP-ALWAYS. Граничный случай — `03-document-pipeline.md:89` («Единый error-блок на `doc_documents`/`ai_analyses` … — канон v1.») — трактован как RENAME: клауза «канон v1» относится к error-block ПАТТЕРНУ (заимствован из v1), не к имени таблицы; `doc_documents`/`ai_analyses` в этом же файле систематически ссылаются на текущие v2-таблицы в остальных 15 вхождениях — асимметричная трактовка одного токена создала бы произвол. Данный токен не входит в буквальный список маркеров D30 («аналог v1 `<token>`» требует токен сразу после маркера — здесь не так), поэтому default RENAME оставлен без эскалации.
