# HANDOFF — 2026-07-29 — after P2.4

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — P2.5 «Расщепление `PromptStep`/`LlmPurpose` и
валидация шага по реестру». `Exec = manual` по §3 Routing (172 call-site'а — `PromptStep::` 67 +
`LlmPurpose::` 105 — и ДВА применителя канона промтов, `PromptSeeder` и `PromptPatchApplier`:
пропуск одного даёт тихий и необратимый разъезд файлового канона с БД). `roadmap.md` — solo, не
батч. `/task:plan-exec` этот item отклонит гейтом п. 2 (`ROUTING-BLOCKED`) — не пытаться.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.5 — сметные кейсы `PromptStep`/`LlmPurpose` уезжают в enum'ы типа (`App\Enums\Procurement\Analyzers\Estimate\**`) под сейм носителя шага из контракта P2.1; строковые ЗНАЧЕНИЯ шагов не меняются, миграции данных и пересева промтов нет |

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P2.5
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 закрыт
🟢** (`fcc0259` + bookkeeping): контракт `DocumentAnalyzer` заморожен гейтом владельца. **P2.2
закрыт 🟢** (`c485dab` + bookkeeping): маршрутизация типизирована на
`AiDocumentType|DocumentTypeScope`, литералы `'estimate'`/`'any'` сняты в 12 call-site'ах. **P2.3
закрыт 🟢** (`2deb5c1` + bookkeeping): `DocumentAnalyzer`, `DocumentAnalyzerRegistry`, DTO сводки и
`EstimateAnalyzer`; ветвление скрининга и операторский re-run переведены на реестр.
**P2.4 закрыт 🟠 (`e06434c` item + bookkeeping):** шестнадцать сметных единиц слоя `Extraction`
(`ExtractionOrchestrator`, оба `ChunkSource`, `Dto/ChunkFetchResult`, `SegmentAssembler`,
`StitchReport`, `EstimateStitcher`/`Persister`/`Validator`, `ValidationReport`,
`EstimateToContractMapper`, `EstimateDiffReport`, `EvalMetrics`, `GoldExampleRecorder`,
`RerunRangeAction`) уехали в `App\Services\Procurement\Analyzers\Estimate`, `EstimateExtractionJob`
— в `App\Jobs\Procurement\Analyzers\Estimate`; в ядре осталось РОВНО пять единиц машинерии
(`ExtractionPreflight`, `ExtractionSession`, `RerunAnalysisAction`, `Dto/PreflightDecision`,
`Exceptions/MaxTokensExceededException`) — их содержимое не изменилось ни на строку. Дифф
перемещённых файлов = 0 строк сверх `namespace`/`use`, три величины гейта baseline D58 не выросли
(255 / 327 / та же гистограмма), сюит 762 passed. Отклонение — дифф вышел за перечень `Files` на 14
путей, физически несших старые FQN. Полные факты — `phases/P2.md` → P2.4 Completion Notes.

**Remaining:** 26 items ⬜. Порядок: P2 (2 из 6: P2.5, P2.6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) →
P7 (4). Батчи B1/B2 и два оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D99**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`findings/extraction-boundary-2026-07-29.md` (§3 классификация — исполнена P2.4; §5 — вход P2.5) ·
`research/01-target-architecture.md` · `roadmap.md` · `phases/P1.md` (`## Phase Handoff`) ·
`phases/P2.md` (P2.1–P2.4 Completion Notes).

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от диффа P2.4: 0**
(на итоговом дереве, после перезаписи handoff).

**Open risks:**
- **Перечень `Files` у items этого плана систематически уже фактического периметра.** P2.4 наступил
  на это второй раз подряд (первый — находка P1.6 «периметр собирается не только от читателей
  колонки, но и от писателей связи и от фабрик»): `Scope Included` предписывал «правку ВСЕХ
  `use`-импортов и ссылок», а `Files` не назвал 11 файлов кода/тестов и каталог
  `tests/Unit/Procurement/Extraction/**`. Цена — 🟠 вместо 🟢 при полностью выполненной работе.
  Кандидаты на ту же болезнь — P2.5 (172 call-site'а), P3.2–P3.5, P4.1: перед их запуском стоит
  сверять `Files` грепом по FQN, а не читать перечень как исчерпывающий.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`
  (`app/Services/Procurement/Extraction/{ExtractionOrchestrator,GoldExampleRecorder}.php`). Не
  правились: вне поимённого перечня `Scope Included` P2.4. Кандидат в scope P7.3 либо fix-item.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре** (`AnalysisStoreRepository::
  firstOrCreate()` `document_type` не пишет; единственный писатель колонки — `ScreeningProcessor:222`).
  P2.3 закрыл это цепочкой «свой `document_type` → `document_type` активного анализа скрининга той
  же версии» ВНУТРИ `RerunAnalysisAction`. Централизация каста/резолва на модели `Analysis` — P2.4
  её НЕ трогал (перемещение без обобщения, D28), кандидат остаётся за P3.5.
- **Тест `outcome` события не фальсифицируем против состояния до P2.3** — полное поведенческое
  доказательство «значение выведено из типа» достижимо только на ВТОРОМ типе, то есть в P2.6.
- **Четыре `*For()`-метода реестра (контракт §5.2) и инвариант сборки §5.4 п. 2 ещё не написаны** —
  отложены в P2.5 вместе с расщеплением enum'ов. Их четыре потребителя (`PromptSeeder`,
  `AiRoutingSeeder`, `PromptForm`, `AiRouteForm`) названы Scope Included P2.5. Если P2.5 не
  реализует их — приёмка §7 контракта не закрыта ничем.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон) — кандидат на
  фикс `plan-design` у будущих items, копирующих эту строку.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`) — наследуется из P2.1, не переоткрывалось.
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено (решение 2026-07-29). Кандидат в scope P5.5 либо item P5.7 — по требованию владельца.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · практика D89-исхода для гейтов-сторожей инварианта без D-записи ·
  `config/ai.php` несёт докблочные упоминания старых имён таблиц · три строки Pending Work про
  `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94) · периметр
  coupling для строковых значений enum'ов — держать на P2.5.

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, P2.1 и
  P2.3, впредь требуется у P3.1 и P4.2 · `docker exec agelto_app` вместо `docker compose exec app`
  — интерполяция `.env` падает на `MINIO_ROOT_PASSWORD` (P2.2, P2.3, P2.4) · env-префикс
  (`-e DB_DATABASE=agelto_test …`) на шаге `config:cache` — иначе страж изоляции прерывает прогон
  (P2.3) · `pint --dirty` автофикс — точечными Edit-правками (host-запуск заблокирован
  permission-гейтом сессии; P2.4 так починил два `ordered_imports`) · `phpstan-baseline.neon`
  правится адресным `sed` по `path:`/FQCN, а не полной регенерацией — регенерация переупорядочила
  бы записи и сделала бы гейт «дифф только `path:`/`message:`» непроверяемым (P2.4) · гейт
  «дифф перемещённых файлов» требует передавать `git diff -M` СТАРЫЕ и НОВЫЕ пути вместе, иначе
  `-M` не спаривает половины переименования и гейт зеленеет на пустом месте (P2.4)
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к
  `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · канонизация
  D89-исхода для гейтов-сторожей инварианта · правка escape-hatch `plan-exec.md` под
  `topology.json` (D98) — репозиторий `swissknifeman`, вне этого плана · раскладка DTO сметы под
  сегмент `Analyzers\Estimate` — решает P3.5, контракт P2.1 её НЕ предписывает · подкаталоги
  внутри `Analyzers/Estimate` (раскладка оставлена плоской, контракт §6.1 её не предписывает) ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5)
- open_questions: в границах плана — НЕТ. Остаются два гейта владельца впереди (P3.1, P4.2). Вне
  плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
