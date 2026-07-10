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
проект нигде не развёрнут, persistent/CI-БД поверх `main` отсутствует. Часть целевых миграций P3
**уже слита в `main`** (D11), но это не блокирует edit-in-place: применённой схемы, с которой
файл мог бы рассинхронизироваться, не существует ни в одной среде. Правка create-table-миграций
на месте + `migrate:fresh` — разрешённая и предпочтительная стратегия (D14, владелец 2026-07-10).

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
- Runner **останавливается** и не идёт дальше в двух случаях (D10, уточнён D14): phase-гейт после
  `P3` без `approvals["after:P3"]`; эскалация исполнителя (план разошёлся с кодом). Проскочить
  гейт нельзя. Прежний hard-гейт `P3.1` («миграции не в `main`») снят D14 и понижен до
  preflight-диагностики — возвращается при появлении первой применённой persistent-среды.
- **Схемные правки (P3, P4):** edit-in-place создающих миграций + `migrate:fresh` (D14). Forward
  alter-миграции (`renameColumn`/`addColumn` поверх опубликованного `create`) не заводим. Ренейм
  считается проверенным только после полного `migrate:fresh` + зелёного сюита, не после grep.
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
| P1 | Решения, ADR, карта фактических имён | 2/3 | 🟠 Done with deviations |
| P2 | Сиды `prc_status_mappings` + аудит `ProcurementStatus` | 1/2 | 🟡 In progress |
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
| D14 | 2026-07-10 | **Q5 `Resolved` (владелец). Схемные правки P3 — `edit-in-place` + `migrate:fresh`.** Факт D11 (целевые миграции на `origin/main`) подтверждён и остаётся в силе, но НЕ влечёт запрет правки на месте: владелец подтвердил, что проект нигде не развёрнут, свежих данных нет, persistent/CI-БД поверх `main` отсутствует. Условие безопасности edit-in-place, сформулированное в D11 («ни одна среда не применяла `main`»), выполнено. **Следствия:** (а) hard-гейт `P3.1` из D11 переформулирован: `git cat-file -e origin/main:<файл>` больше не блокирует, но остаётся диагностикой — при обнаружении файла на `main` P3.1 фиксирует это в Completion Notes и продолжает edit-in-place; (б) P3 правит create-table-миграции на месте, forward `renameColumn`-миграции НЕ заводятся; (в) верификация P3 обязана включать полный `migrate:fresh` + прогон сюита, а не только grep. **Alternatives:** forward alter-миграции — отвергнуто: заводит вечный шов (create со старым именем + alter с новым) в схеме, у которой нет ни одного применённого экземпляра; налог на читаемость без единого бенефициара. **Триггер пересмотра:** первая persistent-среда (CI-БД, staging, прод), применившая миграции → с этого момента edit-in-place запрещён, D14 откатывается к правилу D11. Owner: владелец (решение) + P3.1 (исполнение) | Запрет edit-in-place защищает от рассинхрона схема↔файл на средах, применивших старую версию файла. Таких сред не существует → защищаемого инварианта нет, а цена (шов create+alter на пустой схеме) реальна. Решение действует ровно до появления первой применённой среды |
| D15 | 2026-07-10 | **§4 `findings/P1-actual-names.md` — преждевременный дизайн provenance вычищен, НЕ узаконен в ADR-0008.** Три колонки (`prc_procurements.provenance_source text[]`, `prc_documents.provenance_level enum(raw/validated/derived)`, `prc_estimates.analysis_confidence decimal(3,2)`) удалены из карты имён; §4 заменён указателем на ADR-0008 + D13. **Основания:** (а) карта имён объявлена планом (§2 Execution Rules) КАНОНОМ имён для P2–P5 — гипотеза в каноне читается исполнителем как спека; (б) `provenance_level` с уровнями `raw/validated/derived` — это отклонённая ADR-0008 пятиуровневая `*-derived`-схема в миниатюре, применённая ко всем строкам `prc_documents`, а не к оспариваемым полям; (в) `provenance_source text[]` дублирует существующий coarse-lineage (`prc_revisions.crawler_run_id`, `prc_fetches.artifact_ref`), который ADR-0008 объявил достаточным для вопроса «кто произвёл запись»; (г) `analysis_confidence` — не provenance вовсе, а score модели: отвечает на «насколько LLM уверен», а не на «из какого слоя пришло значение»; протаскивает второй концепт под именем первого; (д) все три предрешают исход research-item'а P4.1 и самим фактом наличия в каноне делают легальный исход «P4 `⛔ Skipped by decision`» психологически недостижимым. **Alternatives:** узаконить в ADR-0008 — отвергнуто (ADR-0008 `Accepted` строит решение на «предмете доказательства»; вписать в него недоказанные колонки = отменить его же Decision); пометить §4 как «черновик» — отвергнуто (D13 уже пробовал пометкой «гипотеза, не спека»; помеченная гипотеза в каноническом документе всё равно копируется исполнителем). Owner: P4.1 (заводит колонки заново, если и только если proof-таблица + потребитель названы) | `grep -rn 'provenance_source\|provenance_level\|analysis_confidence' app/ database/ docs/` = 0 вхождений вне плана: ни одного продюсера, ни одного потребителя. Единственное упоминание — сам план. Материализация в каноне того, чего нет в коде и что запрещено материализовать без доказательства, — прямое нарушение ADR-0008 |
| D16 | 2026-07-10 | **`ProcurementStatus::Announced` удаляется (P2.1). Правило Q1 (D10) исполнено обоими условиями.** (1) `RAG:✅ 2026-07-10 «этапы закупки ЕИС»` (2 независимых прогона, `findings/P2-eis-stages.md`): колонка «Этап закупки» имеет ровно 4 значения (`Подача заявок`/`Работа комиссии`/`Закупка завершена`/`Закупка отменена`), отдельного этапа до подачи заявок НЕТ — публикация извещения сразу даёт `Подача заявок`; этап вычисляется ЕИС по опубликованным документам, в XML такого поля нет. (2) `grep -rn 'Announced' app/ database/ tests/` = 3 вхождения: объявление кейса + 2 присваивания, оба в `database/factories/`. Продовых продюсеров ноль. **Следствие:** кейс удаляется, `ProcurementFactory`/`LotFactory` → `Bidding`, `ProcurementFactory.source_status` `'Размещение извещения'` → `'Подача заявок'` (текущее значение не существует в ЕИС). **`Awarded`/`Unsuccessful` НЕ удаляются** — у них есть названный будущий продюсер (detail-слой, `prc_protocols`), это forward-provision по критерию D5/D13, а не мёртвый кейс. **Alternatives:** (а) оставить `Announced` «на будущее» — отвергнуто: недостижимый кейс enum заставляет каждого читателя `isTerminal()`/UI-веток спрашивать «когда это бывает» и получать «никогда»; критерий forward-provision (названный продюсер + таблица-носитель) не выполнен; (б) переименовать в `Published` — отвергнуто: проблема не в имени, а в отсутствии источника. **Триггер возврата:** источник, отдающий состояние «извещение опубликовано, приём заявок не начат» (223-ФЗ-реестр или detail-слой). Owner: P2.1 | Недостижимый кейс — ложь о пространстве состояний. Enum читают Filament-ресурсы, DTO и `isTerminal()`/`isFailed()`; каждая ветка по `Announced` — мёртвый код, который переживёт удаление причины |
| D17 | 2026-07-10 | **`StatusMappingSeeder` — идемпотентная стартовая матрица для `fz44`, не закрытый codelist.** 4 пары из `findings/P2-eis-stages.md` §1; `updateOrCreate` по натуральному ключу `(registry_id, source_status)` (= `unique`-индекс миграции `100002:24`); вызов из `DatabaseSeeder` ДО `local/testing`-гейта (инфраструктурные данные нужны в prod — та же логика, что у `AiRoutingSeeder`/`PromptSeeder`); нет реестра `fz44` → no-op + `warn`, не исключение. `StatusMappingService::resolve()` **не трогаем**: `firstOrCreate`-тропа в `unknown` остаётся (V2-CORE D5), сид делает её редкой, а не отменяет. **Alternatives:** (а) observer на `Registry::created` для автопровижининга — отвергнуто: в проекте нет ни одного observer'а, паттерн не заводим ради одного случая; вопрос вынесен в `open-questions.md` Q7 (`Exploration`); (б) дефолт-карта константой в `StatusMappingService` — отвергнуто, нарушает V2-CORE D5 «таблица = данные, оператор правит в админке»; (в) валидация «источник обязан быть из карты» — отвергнуто: пятое значение обязано деградировать в `unknown` предсказуемо, а не ронять пайплайн. **Остаточный риск:** реестр, созданный ПОСЛЕ прогона сида, останется без маппингов до повторного `db:seed --class=StatusMappingSeeder` (Q7). Owner: P2.2 | Без сида первый боевой прогон отправит ВСЕ закупки в `unknown` — `canonical_status` перестаёт быть сигналом (дефект `findings/P1-audit-verification.md` §4.2) |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-10 | issue-planner/opus | План создан из черновика `plans/domlang-draft.md`; ACTIVE переключён EST-AI → DOMLANG (EST-AI терминален, `🟠 Done with deviations`) |
| 2026-07-10 | issue-planner/opus | Добавлен `plans/DOMLANG/workflow.js` — per-plan runner с поимённым routing и phase-review веером; общий `estai-phase-runner.js` не переиспользован (хардкод `EST-AI`, лейны из `riskLevel`) |
| 2026-07-10 | issue-planner/opus | D10: гейты пересобраны — owner-гейты P1.3/P2.1 сняты (Q3 противоречил D5; `Announced` → правило решения), единственный phase-гейт перенесён на границу после P3 |
| 2026-07-10 | plan-exec/opus | P1.2 🟢 Done — ADR-0006/0007/0008 приняты, `architecture.md` получил ADR-индекс. Изоморфизм `prc_events`≈OCDS `releases` (immutable) верифицирован кодом до написания 0007 — эскалация не потребовалась. Все 4 Validation-проверки passed |
| 2026-07-10 | plan-exec/opus | P1.3 🟠 Done with deviations — pre-mortem P3/P4 по Klein: 5 сценариев → 3 кластера → D11–D13; эскалация (миграции на `main`) → Q5, закрыта D14. Детали — phases/P1.md P1.3 Completion Notes |
| 2026-07-10 | владелец + main/opus | Q5 `Resolved → D14` (edit-in-place + `migrate:fresh`), Q6 `Resolved → D15` (§4 карты имён вычищен). Детали — §5 D14/D15 |
| 2026-07-10 | plan-design/opus | P2 детализирована (2 items, DoR пройден); RAG → `findings/P2-eis-stages.md`. Решения — §5 D16/D17; Q2 `Resolved → D16`, Q7 заведён |
| 2026-07-10 | plan-exec/sonnet | P2.1 🟢 Done — предпосылка D16 подтверждена (grep=3, 2 в фабриках), `case Announced` удалён + PHPDoc-матрица достижимости; фабрики → `Bidding`, `source_status` → `'Подача заявок'`. Затронут `DiscoveryTest` (произвольная фикстура `'announced'` → `'bidding'`, анти-подгонка Code Guidance). Все 5 Validation passed (462 теста зелёные) |
