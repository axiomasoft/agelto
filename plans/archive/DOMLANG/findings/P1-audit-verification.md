# P1 — Верификация внешнего аудита (RAG + сверка с кодом)

**Дата верификации:** 2026-07-10
**Верифицируемый артефакт:** `agelto_goszakupki_audit_consolidated.md` (внешний аудит, Perplexity;
в репозиторий не коммитился — передан владельцем в чате).
**Метод:** сверка утверждений против кода (`grep`/`Read`) + RAG по внешним фактам
(perplexity-web, 5 запросов).

**Вердикт: ~30% верно · ~40% уже сделано · ~20% фактически неверно · ~10% вредно.**

Аудит писался по спекам `docs/specs/v2/**`, а не по коду. Спеки рассинхронизированы с кодом —
поэтому аудит цитирует несуществующие сущности. Его главная фактическая ценность непреднамеренная:
он **доказал наличие spec drift, споткнувшись об него**.

---

## 1. Утверждения аудита, опровергнутые кодом

| Утверждение аудита | Факт |
|:--|:--|
| Таблицы `doc_documents`, `doc_versions`, `est_estimates` | Не существуют. Реальные: `prc_documents`, `prc_document_versions`, `prc_estimates` (решение D11 EST-AI: `prc_`-мегадомен; миграции переименованы в EST-AI P1) |
| Витрина `prc_search` | Не существует ни в миграциях, ни в коде |
| «Зафиксировать канонический словарь в отдельном `glossary.md`» | Существуют оба: `.ai/guidelines/glossary.md` + `docs/02_Workflow/glossary.md` |
| «Ввести `rows_expected`/`rows_extracted`/`validation_status`/`row_coverage`» | Уже есть: `database/migrations/2026_07_04_100033_create_prc_estimates_table.php:49-51`; `row_coverage` — `app/Services/Procurement/Extraction/EvalMetrics.php:44-100`. Аудит сам их хвалит в разделе «Нейминг, который уже выглядит удачным» — внутреннее противоречие |
| «`crawling` — зонтик над 4 классами работ» | В **коде** разделено: `PipelineDispatcher.php:14` явно назван мостом crawling → пайплайн документов; `PipelineStage` (Prepare/Screening/Extraction) — отдельный enum; `CrawlerMode` — только Fresh/Archive. Зонтичность живёт в документации, не в коде |
| «Запретить `Service` как универсальный контейнер, именовать по роли» | Уже выполнено де-факто: `CsvBlockIngestor`, `DocumentPreparer`, `EstimateStitcher`, `EstimatePersister`, `ExtractionPreflight`, `ExtractionOrchestrator`, `PipelineDispatcher`, `CrawlerRunManager`. Аудит сам оговаривается, что «дерево файлов кода подробно не раскрыто» — рекомендация выдана вслепую |
| «Не видно отдельного выражения документного/crawler-/AI-контекстов в именовании» | Подомены выражены папками: `app/Models/Procurement/{Ai,Crawler,Document,Estimate,Platform}/`, `app/Services/Procurement/{Prepare,Screening,Extraction,Detail,Eis,Modes}/` |

## 2. Утверждения аудита, подтверждённые RAG (но недоработанные)

### 2.1 `source_number` семантически неточен — ВЕРНО, решение аудита неполно

`RAG:✅ 2026-07-10 «44-ФЗ/223-ФЗ ЕИС: извещение, реестровый номер, редакция, протокол, лот»`
Источники: `zakupki-kontur.ru/news/numerologiya-zakupok/`, `cpp-group.ru`, `etpsp.ru/info/glossary.html`, `v1a.ru`

- Канон ЕИС — **«реестровый номер извещения»**: ключ записи извещения в реестре ЕИС, к нему
  привязаны лоты, протоколы, контракты.
- **Аудит не заметил:** по **223-ФЗ у закупки ДВА номера** — номер извещения в ЕИС **и** отдельный
  номер, присвоенный ЭТП. По 44-ФЗ — один. Значит `notice_number` (вариант аудита) неверен;
  правильно `registry_number` + отдельное nullable-поле под номер ЭТП.
- «Номер закупки» — иной термин: живёт в плане-графике и в структуре ИКЗ (разряды 23–26), **не**
  идентификатор извещения. Использовать его как имя колонки нельзя.
- Редакция извещения: номер остаётся тем же, редакция — инкрементальная версия. Совпадает с
  реализованным `prc_revisions` + `source_edition`.
- Уникальность лота: `реестровый номер извещения + номер лота`.

### 2.2 Этапы процедуры ЕИС

`RAG:✅ 2026-07-10 «ЕИС этапы закупки 44-ФЗ»` — источник: `wiki.gosplan.info/docs/purchases`

Фактические этапы интерфейса ЕИС: **«подача заявок» → «работа комиссии» → «закупка завершена» /
«закупка отменена»**. Логика переходов, реконструированная по документам:

- публикация извещения → «подача заявок»;
- публикация любого протокола → «работа комиссии»;
- документ о результате определения поставщика → «завершена» (включая ед. поставщика) либо
  «отменена» (прекращена / признана несостоявшейся).

Единого XML-поля «этап» в ЕИС нет — этап реконструируется по составу документов.
Состояния «планируются» / «не состоялась» — детализация финального статуса во внешних сервисах,
не отдельные технические этапы.

### 2.3 Provenance — направление верно, реализация аудита избыточна

`RAG:✅ 2026-07-10 «data provenance/lineage: W3C PROV vs прагматичные колонки»` — источник:
`w3.org/TR/prov-dm/` + PVLDB fine-grained provenance

Правило окупаемости: **fact-level provenance материализуют только там, где ответ должен пережить
удаление, ре-агрегацию, дедупликацию или согласование между источниками.** Если вопрос звучит
«какой job/источник произвёл эту таблицу» (а не «почему здесь именно это значение») — достаточно
coarse-колонок. Fine-grained захват несёт документированный storage/processing overhead.

Рекомендуемый практикой tiered-подход: coarse-lineage на каждой записи, fact-level — только для
10–20% полей (user-visible / регулируемых / оспариваемых / model-critical).

Аудит предлагает 5 уровней (`registry-derived` … `validated-derived`) **на все факты** — прямое
нарушение правила.

**Coarse-lineage в проекте уже существует:**
`prc_revisions.crawler_run_id` · `prc_fetches.artifact_ref` (сырой CSV) ·
`prc_ai_analyses.{provider,model,rules_hash,cache_id}` · `prc_estimates.raw_result(_ref)` ·
`prc_documents.source_url`.

### 2.4 Разделение осей состояния — верно, но уже реализовано

Четыре независимые машины состояний **уже существуют раздельно**: `ProcurementStatus` (8 кейсов) ·
`DocumentStatus` (8) · `AnalysisStatus` (5) + `AnalysisStep` (2) · `RunStatus` (5).
Не хватает **одного сводного документа**, а не рефакторинга. Валидная, дешёвая находка.

Также валидно и отсутствует: `entity-lifecycle-map`.

## 3. Рекомендация аудита, опровергнутая RAG

`RAG:✅ 2026-07-10 «DDD bounded contexts в Laravel modular monolith: цена и риск большого
namespace-ренейма»`

Порог окупаемости ренейма (все условия должны выполняться):
≥2 подомена с **различными** ubiquitous language · инварианты, которые не должны протекать между
ними · разные регимы масштабирования/комплаенса · организационная структура уже отражает границы.

Проект **не проходит ни по одному критерию**: одна команда, один язык («закупка» — один
интегрированный процесс), нет разных SLA/владельцев. Подомены уже выражены папками.
Цена: 341 PHP-файл, 99 тестов, Eloquent morph maps, policy mapping, Filament Resource discovery,
route-model binding, factories.

Вердикт RAG для такого случая: *«renaming probably doesn't pay off yet … wait until you have
evidence of real domain boundaries and organizational drivers»*. При решении делать — только
strangler (фасад-алиасы → миграция use-statements → hard rename), никогда big-bang.

## 4. Чего аудит не увидел — сильнее всех его находок

### 4.1 OCDS — отраслевой стандарт словаря существует, аудит его не упомянул

`RAG:✅ 2026-07-10 «OCDS 1.2: release/compiledRelease/tender/award — отраслевой стандарт?»`
Источники: `standard.open-contracting.org/latest/en/primer/releases_and_records/`,
`link.springer.com/chapter/10.1007/978-3-030-29374-1_19`

Open Contracting Data Standard — де-факто глобальный стандарт публикации закупочных данных.
Канон: `release` (immutable-снапшот при изменении, ключ `ocid`) · `record` (индекс релизов) ·
`compiledRelease` (последнее состояние каждого поля) · `versionedRelease` (история изменений
на уровне поля) · секции `planning`/`tender`/`award`/`contract`/`implementation`.

**Проект уже реализовал изоморф, не зная об этом:**
`prc_procurements` ≈ compiledRelease · `prc_revisions` ≈ versionedRelease · `prc_events` ≈ releases.

Критично: RAG прямо фиксирует, что **OCDS — exchange/publication layer, а не внутренняя
транзакционная схема**. Национальные системы держат свою нормализованную схему и мапят в OCDS
для прозрачности и аналитики; 44-ФЗ/223-ФЗ требуют профилей/расширений поверх ванильного OCDS.

Следствие: аудитовская таксономия (`Source Intake` / `Notice Enrichment` / `Estimate Intelligence`)
— изобретение словаря там, где есть отраслевой. Но и «переименовать домены в OCDS» неверно.
Правильный ход — ADR + mapping-таблица как экспортный контракт.

### 4.2 `prc_status_mappings` не сидируется — рабочий дефект

`app/Services/Procurement/StatusMappingService.php:19` — `firstOrCreate` на незнакомую пару
`(registry_id, source_status)` заводит строку с `canonical_status = 'unknown'`.
Сидов нет: `grep` по `database/seeders/` не находит ни `announced`, ни `bidding`, ни «подача заявок».
`ProcurementStatus::Announced` присваивается **только в фабриках**
(`ProcurementFactory.php:27`, `LotFactory.php:26`) — в продовом пути ни разу.

**Следствие:** на первом боевом прогоне все закупки получат `unknown`, пока оператор не размапит
каждую пару вручную в админке. Дефект, а не вопрос вкуса.

Дополнительно к проверке: достижим ли `Announced` вообще — по фактическим этапам ЕИС (§2.2)
публикация извещения сразу даёт «подача заявок» (≈ `Bidding`).

### 4.3 Spec drift — 166 вхождений мёртвых префиксов

`docs/specs/v2/01-database.md` §0.1 объявляет канон `prc_`/`ref_`/`org_` — и **тело той же спеки
даёт 79 вхождений** `plt_`/`doc_`/`ai_`/`crw_`/`sys_`/`est_`.

| Файл | Вхождений |
|:--|--:|
| `docs/specs/v2/01-database.md` | 79 |
| `docs/specs/v2/07-carryover-from-v1.md` | 29 ¹ |
| `docs/specs/v2/03-document-pipeline.md` | 17 |
| `docs/specs/v2/04-llm-providers.md` | 12 |
| `docs/specs/v2/02-crawling.md` | 11 |
| `docs/specs/v2/06-roadmap.md` | 7 |
| `docs/specs/v2/05-admin-observability.md` | 7 |
| `docs/specs/v2/08-estimate-extraction.md` | 4 |
| `docs/specs/v2/00-overview.md` | 0 |

¹ Частично **легитимно** — файл описывает таблицы v1. Также затронуты `docs/03_Dev/database.md`
и `docs-private/**` (включая `history/`, где v1-имена легитимны).

Зафиксировано как открытый долг в `plans/EST-AI/handoff.md` (строки 64–65).

### 4.4 `PipelineDispatcher` не мутирует статус документа

`docs-private/crawler-gotchas.md` §5 + `docs-private/risk-register.md`: мост **только отбирает и
логирует** `status = downloaded`, не переводя документ дальше. Решение D17 (EST-AI) не выполнено
буквально — согласовано ревьюером как компромисс, но **прикладной защиты от повторной постановки
того же документа в очередь нет**. Тот же класс риска, что v1 C1, сдвинутый на слой вперёд.

---

## 5. Что из аудита взято в план DOMLANG

| Находка аудита | Куда |
|:--|:--|
| `source_number` неточен | P3 (+ `etp_number`, чего аудит не заметил) |
| Provenance / уровни доверия | P4 — тонкий срез, не 5 уровней |
| Смешение `status`/`stage`/`state`/`step` | P8 — сводный документ, не рефакторинг |
| `entity-lifecycle-map` отсутствует | P8 |
| Спеки смешивают канон и реализацию | P6 (переформулировано как spec drift) |
| Отдельный `glossary.md` | P7 — дополнение существующих, не создание |

## 6. Что отклонено (основание — этот документ)

| Рекомендация | Основание отказа |
|:--|:--|
| Namespace-ренейм в 7 bounded contexts | §3 — RAG-порог не пройден → ADR-0006 |
| 5 уровней `*-derived` на все факты | §2.3 — нарушает правило окупаемости → ADR-0008 |
| `Crawling` → `Source Intake` в коде | §1 — в коде уже разделено |
| Запретить `Service` | §1 — выполнено де-факто |
| `rows_expected`/`validation_status` ввести | §1 — уже есть |
| `prc_eis_details` → `prc_notice_eis_details` | Ренейм ради ренейма; `EisDetails` — типизированный extension-шов 1:1, не свалка |
| `detail_fetched_at` → `notice_enriched_at` | Аудит сам называет группу `detail_*` «good-enough» |
| `raw_result` → `extraction_payload` | Текущее имя точнее: это сырой ответ LLM, а не payload стадии |
