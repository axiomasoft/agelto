# DOMLANG — Семантическое уплотнение домена + закрытие реальных дефектов

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | DOMLANG |
| Title | Доменный язык, provenance и закрытие дефектов, найденных при верификации внешнего аудита |
| Version | 0.1.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | opus |
| Last Updated | 2026-07-10 |
| Repository | agelto (branch `feat/v2-scaffold`) |
| Related Packages | — (изменения внутри приложения; внешних пакетов план не трогает) |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · haiku (LOW) · opus (design/audit) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/DOMLANG |
| Paused By | — |

## 1. Context

Владелец передал внешний аудит репозитория (Perplexity, `agelto_goszakupki_audit_consolidated.md`)
с рекомендациями по доменной декомпозиции и неймингу. Аудит верифицирован против кода и через RAG
(выжимка — `findings/P1-audit-verification.md`, читать первым). Вердикт: ~30% верно, ~40% уже
сделано, ~20% фактически неверно, ~10% вредно.

Причина ошибок аудита установлена: он писался по спекам `docs/specs/v2/**`, которые разошлись с
кодом — 166 вхождений мёртвых префиксов таблиц (`doc_`/`est_`/`ai_`/`crw_`/`sys_`/`plt_`), причём
`01-database.md` §0.1 объявляет канон `prc_`/`ref_`/`org_`, а тело той же спеки ему противоречит.
Аудит непреднамеренно доказал наличие spec drift, споткнувшись об него.

Этот план берёт из аудита подтверждённое, отвергает опровергнутое (с фиксацией в ADR, чтобы вопрос
не поднимался в третий раз) и добавляет три дефекта, которых аудит не увидел, потому что не читал
код: (1) `prc_status_mappings` не сидируется — на первом боевом прогоне все закупки получат
`canonical_status = 'unknown'`; (2) spec drift; (3) `PipelineDispatcher` не мутирует статус
документа (решение D17 плана EST-AI не выполнено буквально) — нет защиты от двойной постановки
документа в очередь.

Плюс находка RAG, отсутствующая в аудите: существует отраслевой стандарт словаря закупок — OCDS,
и проект уже реализовал его изоморф (`prc_procurements` ≈ compiledRelease, `prc_revisions` ≈
versionedRelease, `prc_events` ≈ releases), не зная об этом.

Предшественник: план EST-AI (`🟠 Done with deviations`, закрыт 2026-07-09). Прод-данных нет,
миграции не слиты в `main` — правка миграций на месте разрешена (прецедент EST-AI P1).

## 2. Execution Rules

- Один item за раз; статус обновляется В ПЛАНЕ (phase-файл + Status Board) до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (plan-protocol §6).
- План разошёлся с кодом → не импровизировать, эскалировать (plan-protocol §10).
- После закрытия item/фазы — перезаписать `plans/DOMLANG/handoff.md` целиком.
- **Порядок фаз неизменен: код (P2–P5) перед документацией (P6–P8).** Обратный порядок заставит
  переписывать спеки дважды. Перепрыгивать через фазы запрещено.
- До закрытия P6 спеки `docs/specs/v2/**` — **ненадёжный источник имён таблиц**. Канон имён для
  фаз P2–P5 — `findings/P1-actual-names.md` (Deliverable P1.1) и сами миграции.
- Тесты: Pest, `test('<интент по-русски>')`, БД `agelto_test`, запуск
  `docker compose exec app php artisan test --filter=<Subject>`; стиль
  `docker compose exec app ./vendor/bin/pint --dirty --test`.
- Персистентность — только через `app/Repositories/Procurement/*StoreRepository`; сервисы
  `final readonly`, конструкторная инъекция, именованные аргументы, `declare(strict_types=1)`.
- LLM-вызовы в тестах — только `tests/Support/Doubles/FakeLlmProvider`; реальных запросов нет.
- Коммит-гейт закрытия item'а (plan-protocol §6): диф закоммичен, Conventional Commits по-русски,
  `pint --dirty --test` зелёный ДО коммита, review-фиксы — отдельным коммитом (не `amend`).
- `Old/`, `tmp/agelto*.md` — read-only справочники; ничего в них не менять.
- **Runner плана — `plans/DOMLANG/workflow.js`** (живёт в папке плана: план удалён → хвостов нет).
  Прогоняет фазу целиком: impl → item-review → fix → mid-phase checkpoint → phase-review веером.
  Лейны моделей в нём **декларированы поимённо** по §3 ниже. Меняешь §3 → меняешь `workflow.js`;
  расхождение между ними = дефект. Общий `.claude/workflows/estai-phase-runner.js` для DOMLANG
  **не годится** (хардкодит `EST-AI` в коммитах, лейны выводит из `riskLevel` и игнорирует §3).
- Runner **останавливается** и не идёт дальше в трёх случаях (D10): phase-гейт после `P3` без
  `approvals["after:P3"]`; эскалация исполнителя (план разошёлся с кодом); непройденный hard-гейт
  `P3.1` (`git log origin/main -- database/migrations/` непуст). Проскочить гейт нельзя.
- Всё остальное решается **правилами решения**, а не усмотрением: правило для `Announced` —
  в `workflow.js` `DECISION_RULES['P2.1']`. Правило не покрывает найденный факт → эскалация,
  не догадка.

## 3. Routing

Отклонения от SSOT-матрицы (plan-protocol §9):

- `P1.1`, `P6.1` | haiku/low | Механический recon (grep/map/inventory). Thinking вреден — жжёт
  токены без выигрыша; результат верифицируется машинным грепом, а не рассуждением.
- `P1.2`, `P1.3` | opus/xhigh | ADR несущих архитектурных решений + pre-mortem. Отклонение решений
  внешнего аудита обязано быть обосновано так, чтобы вопрос не поднимался повторно.
- `P2.1` | opus/high И perplexity-web/WebFetch (RAG) | Доменные факты ЕИС (этапы процедуры) —
  внешний факт, память недопустима; решение о судьбе `ProcurementStatus::Announced` меняет enum.
- `P4.1` | opus/high | Research-item: доказать наличие конфликтных полей ДО написания кода.
  Отрицательный результат — легальный (фаза закрывается `⛔ Skipped by decision`).
- `P9.1`, `P9.2` | opus/xhigh | Финальная верификация и adversarial-аудит фаз.

Review-гейты (субагенты, read-only, вне матрицы моделей):

- После каждой кодовой фазы (P2–P5) — `agelto-reviewer`.
- P3, P4 (схема БД) — `agelto-db-impact` ДО правки и `agelto-reviewer` после.
- P5 (идемпотентность, двойной dispatch) — `agelto-security-review`.

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | Решения, ADR, карта фактических имён | 3/3 (2🟢+1🟠) | 🟠 Done with deviations |
| P2 | Сиды `prc_status_mappings` + аудит `ProcurementStatus` | 0/2 | ⬜ Not started |
| P3 | Точечный семантический ренейм БД и кода | 0/3 | ⬜ Not started |
| P4 | Provenance: тонкий срез по конфликтным полям | 0/2 | ⬜ Not started |
| P5 | Закрытие реальных дефектов пайплайна | 0/3 | ⬜ Not started |
| P6 | Убить spec drift | 0/2 | ⬜ Not started |
| P7 | Глоссарий v2 + OCDS mapping | 0/2 | ⬜ Not started |
| P8 | Lifecycle-map и state machines единым документом | 0/2 | ⬜ Not started |
| P9 | Финальный cross-phase review | 0/3 | ⬜ Not started |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-10 | Источник — черновик `plans/domlang-draft.md` | bootstrap-транскрипция одобренного черновика plan mode |
| D2 | 2026-07-10 | Namespace-ренейм (`SourceIntake`/`NoticeEnrichment`/`DocumentLifecycle`/`EstimateIntelligence`/`AiOrchestration`) — **отклонён**; фиксируется ADR-0006 с триггером пересмотра | `RAG:✅ 2026-07-10 «DDD bounded contexts в Laravel modular monolith»`: порог окупаемости — ≥2 различных ubiquitous language + непротекающие инварианты + разные SLA/владельцы. Проект не проходит ни по одному. Подомены уже выражены папками. **Alternatives:** (а) big-bang ренейм всех 7 контекстов — отвергнут (341 PHP-файл, 99 тестов, morph maps, Filament discovery); (б) strangler одного подомена через фасад-алиасы — отвергнут как преждевременный, но записан в ADR-0006 как способ исполнения, если триггер сработает |
| D3 | 2026-07-10 | OCDS — **exchange layer**, не внутренняя схема. Фиксируется ADR-0007 + mapping-таблица (P7.2) | `RAG:✅ 2026-07-10 «OCDS 1.2 industry standard?»`: национальные системы держат нормализованную схему и мапят в OCDS для публикации; 44-ФЗ/223-ФЗ требуют профилей поверх ванильного. Проект уже реализовал изоморф (compiledRelease/versionedRelease/releases). **Alternatives:** (а) сделать OCDS primary persistence — отвергнут (RAG прямо не рекомендует); (б) игнорировать OCDS — отвергнут (интеграции и аналитика ожидают OCDS как поверхность) |
| D4 | 2026-07-10 | Provenance — **тонкий срез**: материализуем только поля, приходящие из ≥2 слоёв С расхождением. Фиксируется ADR-0008 | `RAG:✅ 2026-07-10 «W3C PROV vs прагматичные колонки»`: fact-level provenance окупается, только если ответ должен пережить дедупликацию/согласование источников; иначе coarse-колонки. Coarse-lineage в проекте уже есть. **Alternatives:** (а) 5 уровней `*-derived` на все факты (вариант аудита) — отвергнут как налог без доказанного потребителя; (б) не делать вовсе — отвергнут, но остаётся исходом P4, если P4.1 не найдёт конфликтов |
| D5 | 2026-07-10 | Семантический ренейм колонок — **точечный**: `source_number`→`registry_number`, `source_guid`→`registry_guid`, `source_status`→`source_stage`, **+ новое** `etp_number` (nullable) | `RAG:✅ 2026-07-10 «44-ФЗ/223-ФЗ: реестровый номер извещения»`: канон ЕИС — «реестровый номер извещения»; по 223-ФЗ у закупки ДВА номера (ЕИС + ЭТП) — аудит этого не заметил, предлагая `notice_number`. `source_status` хранит сырой **этап** ЕИС, не канонический статус. **Alternatives:** `notice_number` — отвергнут (по 44-ФЗ он же реестровый, по 223-ФЗ неоднозначен); ренейм всей `source_*`-лексики — отвергнут (см. D6) |
| D6 | 2026-07-10 | `raw_result`, `prc_eis_details`, `detail_*`, `Crawler.source_method` — **не переименовываем** | `raw_result` честно называет сырой ответ LLM (не «payload стадии»). `EisDetails` — типизированный extension-шов 1:1, не свалка. Группу `detail_*` аудит сам назвал «good-enough». `source_method` (csv/html/api) — действительно про метод забора. Ренейм ради ренейма увеличивает диф без семантического выигрыша |
| D7 | 2026-07-10 | Порядок фаз: код (P2–P5) **перед** документацией (P6–P8) | Обратный порядок заставит переписать спеки дважды — сначала под фактические имена, потом под новые (P3). Прецедент EST-AI: документационные фазы P10/P12 шли терминальными. Побочный риск (исполнители P2–P5 читают лгущие спеки) снимается Deliverable P1.1 — картой фактических имён |
| D8 | 2026-07-10 | `prc_status_mappings` сидируется; судьба `ProcurementStatus::Announced` решается в P2.1 | `RAG:✅ 2026-07-10 «этапы закупки ЕИС»`: фактические этапы — «подача заявок» → «работа комиссии» → «завершена»/«отменена». Публикация извещения СРАЗУ даёт «подача заявок» (≈ `Bidding`), поэтому `Announced` может быть недостижим. Сейчас он присваивается только в фабриках; `StatusMappingService::resolve()` на незнакомую пару пишет `unknown` → без сидов вся выдача уедет в `unknown` |
| D9 | 2026-07-10 | Финальное `/code-review ultra` запускает **владелец**, не агент | Команда user-triggered и billed; агент её не инициирует (ограничение харнесса). Фиксируется как item P9.3 с явным владельцем-исполнителем |
| D10 | 2026-07-10 | Автопрогон рвётся **один раз — после P3**. Owner-гейты на P1.3 и P2.1 сняты: Q3 → `Resolved → D5` (решение уже было принято, вопрос был дефектом плана), Q1 → детерминированное правило решения по `Announced` | Гейт ставится там, где дифф необратим по объёму, а не там, где «страшно». `phase-review` умеет пометить фазу `🟠`, но не умеет её откатить: если P3 (ренейм в 341 файле, 99 тестов) закроется `🟠`, фазы P4–P8 лягут поверх полусломанного ренейма, а P6 зафиксирует его в документации как канон. **Alternatives:** (а) два owner-гейта на P1.3/P2.1 — отвергнуто (Q3 противоречил D5; судьба `Announced` выражается проверяемым правилом, а не усмотрением); (б) без гейтов вовсе — отвергнуто (тихое накопление отклонений через 9 фаз); (в) гейт после каждой кодовой фазы — отвергнуто как налог: P2/P4/P5 обратимы поитемными коммитами |
| D11 | 2026-07-10 | **Pre-mortem P1.3 · кластер A (сценарий 1) — гейт D10 «edit-in-place» уже нарушен фактом.** Target-миграции P3 присутствуют на `origin/main` (verified 2026-07-10: `2026_07_04_100011` procurements, `100002` status_mappings, `100020` protocols, `100044` ignored_items — `git cat-file -e origin/main:…` = present; `git log origin/main -- database/migrations/` = 33 коммита EST-AI). **Mitigation:** `P3.1` обязан прогнать `git cat-file -e origin/main:<файл>` по каждому целевому файлу; при наличии файла на `main` правка create-table на месте ЗАПРЕЩЕНА → P3 переходит на forward alter-миграции (`renameColumn`/`addColumn`), не редактирует опубликованный файл. Стратегическая развилка (edit-in-place ещё допустим, если ни одна persistent/CI-среда не применяла `main`) вынесена владельцу — `open-questions.md` Q5 `Decision pending (owner needed)`. Owner: P3.1 + владелец (Q5) | §1 Context / D10 / P3 Phase Context / handoff Open Risk утверждают «миграции не слиты в `main`» — опровергнуто git'ом. Правка опубликованного create-table-файла НЕ перезапускается на средах, уже применивших старую версию (Laravel трекает по имени файла) → рассинхрон схема↔код класса C1/C5 (risk-register #1). Сценарий 1 pre-mortem'а реализован, не гипотетичен (Code Guidance P1.3 прямо запрещал его ослаблять) |
| D12 | 2026-07-10 | **Pre-mortem P1.3 · кластер B (сценарии 2, 5) — полисемия и меж-табличная связка ренейм-колонок.** `source_number` живёт на 3 таблицах: `prc_procurements` (= реестровый номер, D5-цель), `prc_ignored_items` (тот же концепт, natural-key `unique(registry_id, source_number)`, вне D5-скоупа) и `prc_protocols` (СОБСТВЕННЫЙ номер протокола, nullable — **другой концепт, переименовывать нельзя**). `source_status` — на `prc_procurements` И `prc_status_mappings` (связаны через `StatusMappingService::resolve()`; вторая сидируется в P2 ДО ренейма P3). **Mitigation:** (a) `P3.1` impact-карта классифицирует КАЖДОЕ вхождение `source_number`/`source_status`/`source_guid` по таблице-владельцу и концепту; ренейм анкорится на Procurement-scoped код, НЕ на голый токен (skill `namespace-refactor-safety`); (b) `P3.1` явно решает и документирует, переименовывается ли `prc_status_mappings.source_status` (+ его `unique`-индекс, модель `StatusMapping`, `StatusMappingFactory`, `StatusMappingService::resolve()`, **сидер из P2**) или остаётся асимметрия `source_stage`↔`source_status` с обоснованием; (c) `P3.3` zero-residue grep ОБЯЗАН покрывать `database/seeders/` и `database/factories/` и подтвердить, что `prc_protocols.source_number`/`prc_ignored_items.source_number` НЕ задеты. Owner: P3.1 (решение) + P3.3 (верификация) | Токен-левел find-replace `source_number`→`registry_number` сломает `prc_protocols`/`prc_ignored_items`; незамеченный `prc_status_mappings.source_status` или несинхронный сидер P2 развалит резолв статуса в рантайме (сценарии 2, 5). Morph maps в коде отсутствуют (`grep`=0) — этот подриск N/A; но Filament `ProcurementInfolist`, DTO, репозитории, сервисы, 4 фабрики и тесты — задеты (verified) |
| D13 | 2026-07-10 | **Pre-mortem P1.3 · кластер C (сценарии 3, 4) — материализация без продюсера/потребителя.** (3) `etp_number` имеет полный write-path (DTO `ProcurementSnapshotData::$etpNumber` → `ProcurementStoreRepository:21` → колонка), но НИ ОДИН источник его не заполняет: `EisCsvRowMapper` не передаёт `etpNumber`, `ProcurementFactory:24` хардкодит `null`. Это принятая forward-provision (D5), не баг. **Mitigation:** `P3.3` пинит write-path Pest-тестом round-trip (`etpNumber: '…'` → `prc_procurements.etp_number` через store-репозиторий), чтобы будущий 223-ФЗ-продюсер не смог тихо промахнуться; триггер оживления колонки — онбординг первого 223-ФЗ-реестра/ЭТП (Q3 остаётся `Resolved → D5`). Owner: P3.3. (4) provenance-колонки: `findings/P1-actual-names.md` §4 спекулятивно перечисляет `provenance_source`/`provenance_level`/`analysis_confidence` — это **гипотеза, не спека**. **Mitigation:** `P4.1` обязан выдать proof-таблицу (поле → ≥2 code-write-site → конкретный пример расхождения) И назвать ПОТРЕБИТЕЛЯ (кто читает provenance); `P4.2` материализует ТОЛЬКО доказанные поля с названным потребителем; нет доказательства или нет потребителя → P4 `⛔ Skipped by decision` + триггер в ADR-0008. Owner: P4.1/P4.2 | Материализация без продюсера (etp) или без потребителя (provenance) = мёртвая колонка / налог без выигрыша (наруш. ADR-0008). §4 actual-names нельзя трактовать как коммит на схему (сценарии 3, 4) |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-10 | issue-planner/opus | План создан из черновика `plans/domlang-draft.md`; ACTIVE переключён EST-AI → DOMLANG (EST-AI терминален, `🟠 Done with deviations`) |
| 2026-07-10 | issue-planner/opus | Добавлен `plans/DOMLANG/workflow.js` — per-plan runner с поимённым routing и phase-review веером; общий `estai-phase-runner.js` не переиспользован (хардкод `EST-AI`, лейны из `riskLevel`) |
| 2026-07-10 | issue-planner/opus | D10: гейты пересобраны — owner-гейты P1.3/P2.1 сняты (Q3 противоречил D5; `Announced` → правило решения), единственный phase-гейт перенесён на границу после P3 |
| 2026-07-10 | plan-exec/opus | P1.2 🟢 Done — ADR-0006/0007/0008 приняты, `architecture.md` получил ADR-индекс. Изоморфизм `prc_events`≈OCDS `releases` (immutable) верифицирован кодом до написания 0007 — эскалация не потребовалась. Все 4 Validation-проверки passed |
| 2026-07-10 | plan-exec/opus | P1.3 🟠 Done with deviations — pre-mortem P3/P4 по Klein: 5 обязательных сценариев → 3 кластера → D11–D13 (mitigations с owner+item). **Escalation (forward, P3):** target-миграции P3 уже на `origin/main` → предпосылка D10/§1 «edit-in-place, миграции не в main» опровергнута фактом; стратегия схемных правок P3 вынесена владельцу — `open-questions.md` Q5 `Decision pending`. 0 живых `[NEEDS CLARIFICATION]` в P3/P4. Фаза P1 терминальна (3/3, 2🟢+1🟠) |
