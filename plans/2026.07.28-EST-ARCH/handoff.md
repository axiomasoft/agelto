# HANDOFF — 2026-07-30 — after P2.5

**Next:** `/task:plan-design 2026.07.28-EST-ARCH P2.5` — починить ТЗ P2.5. Item в `🔴 Blocked`,
кода не написано, коммитов по коду нет. Причина: **Validation 1–2 привязаны к путям
`app/Enums/Procurement/{PromptStep,LlmPurpose}.php`, которые ЗАМОРОЖЕННЫЙ контракт P2.1 §4.1
предписывает прекратить существовать** (интерфейсы — `App\Enums\Llm\{PromptStep,LlmPurpose}`,
ядерные enum'ы — `App\Enums\Llm\{CorePromptStep,CoreLlmPurpose}`): зелёный исход гейта недостижим
ни при каком исполнении в границах `Scope` (plan.md §2 D89, исход «в» — эскалация ДО написания
кода, не `🟠` после). Полный перечень того, что обязан починить заход, — `phases/P2.md` → P2.5
**Pending Work** (пять пунктов: блокирующий гейт · формулировка Scope против §4.5 контракта ·
дрейф путей в `Files`/`Required Reads` · периметр `Files` уже фактического · неразрешённая дыра
резолва `purpose` без `document_type`).

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Привести ТЗ P2.5 в соответствие с замороженным контрактом P2.1 §4.1 и с фактическим периметром правки; после этого P2.5 исполняется заново (`implementation/high`, `Exec = manual`) |

```
/task:plan-design 2026.07.28-EST-ARCH P2.5
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 закрыт
🟢** (`fcc0259` + bookkeeping): контракт `DocumentAnalyzer` заморожен гейтом владельца. **P2.2
закрыт 🟢** (`c485dab` + bookkeeping): маршрутизация типизирована на
`AiDocumentType|DocumentTypeScope`, литералы `'estimate'`/`'any'` сняты в 12 call-site'ах. **P2.3
закрыт 🟢** (`2deb5c1` + bookkeeping): `DocumentAnalyzer`, `DocumentAnalyzerRegistry`, DTO сводки и
`EstimateAnalyzer`; ветвление скрининга и операторский re-run переведены на реестр. **P2.4 закрыт
🟠** (`e06434c` item + bookkeeping): шестнадцать сметных единиц слоя `Extraction` и
`EstimateExtractionJob` уехали в `**\Analyzers\Estimate\**`, в ядре осталось РОВНО пять единиц
машинерии; отклонение — дифф вышел за перечень `Files` на 14 путей. Полные факты — `phases/P2.md`
→ P2.4 Completion Notes.

**P2.5 — 🔴 Blocked (2026-07-30):** прочитаны все Required Reads, прогнаны гейты Validation на
`<base>` = `b4449c5` (1–3 красные, 9/11 — гейты-сторожа), снят фактический периметр
(`PromptStep::` — 88 call-site'ов, `LlmPurpose::` — 116 на `b4449c5`, против 67/105 в §3 Routing:
те числа сняты на `74f515b`). Мутаций кода нет.

**Remaining:** 26 items ⬜ + P2.5 🔴. **Фаза P2 переведена в `🔴 Blocked`** (Status Board §4 —
требование `plan_lint.py`: фаза с заблокированным item'ом не может стоять в `🟡`); снимется вместе
с разблокировкой P2.5. Порядок: P2 (P2.5 после починки ТЗ, затем P2.6) → P3 (6) →
P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и два оставшихся гейта владельца (P3.1, P4.2) —
`roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D99**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes`; именно
§4.1 последнего перебивает Validation 1–2 P2.5 ·
`findings/extraction-boundary-2026-07-29.md` (§5 — вход P2.5) ·
`research/01-target-architecture.md` · `roadmap.md` · `phases/P1.md` (`## Phase Handoff`) ·
`phases/P2.md` (P2.1–P2.4 Completion Notes, P2.5 Pending Work).

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от диффа P2.5: 0**
(на итоговом дереве, после перезаписи handoff).

**Open risks:**
- **ТЗ P2.5 разошлось с замороженным контрактом P2.1 — блокирующее, чинит `plan-design`.** Класс
  находки новый для этого плана: предыдущие расхождения были «`Files` уже периметра», здесь гейт
  анкерен на путь, который сам item обязан снести. Кандидаты на ту же болезнь — любой item,
  чья Validation грепает файл, переезжающий внутри того же item'а (по §3 Routing это P3.2, P3.3,
  P3.5).
- **Перечень `Files` у items этого плана систематически уже фактического периметра.** P2.5 — третий
  случай подряд (P1.6, P2.4). Перед запуском P3.2–P3.5 и P4.1 сверять `Files` грепом по FQN, а не
  читать перечень как исчерпывающий.
- **Числа периметра в §3 Routing и Code Guidance сняты на `74f515b`** (до P2.1–P2.4) и разошлись с
  фактом: `PromptStep::` 67 → 88, `LlmPurpose::` 105 → 116 (на `b4449c5`). Формулировки «172
  call-site'а» в §3 Routing, `handoff`-истории и контракте §4.5 читать как порядок величины.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`
  (`app/Services/Procurement/Extraction/{ExtractionOrchestrator,GoldExampleRecorder}.php`). Кандидат
  в scope P7.3 либо fix-item.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре** (`AnalysisStoreRepository::
  firstOrCreate()` `document_type` не пишет; единственный писатель колонки — `ScreeningProcessor:222`).
  Централизация каста/резолва на модели `Analysis` — кандидат за P3.5.
- **Тест `outcome` события не фальсифицируем против состояния до P2.3** — полное поведенческое
  доказательство «значение выведено из типа» достижимо только на ВТОРОМ типе, то есть в P2.6.
- **Четыре `*For()`-метода реестра (контракт §5.2) и инвариант сборки §5.4 п. 2 ещё не написаны** —
  ждут P2.5. Их четыре потребителя (`PromptSeeder`, `AiRoutingSeeder`, `PromptForm`, `AiRouteForm`)
  названы Scope Included P2.5, но в `Files` не перечислен ни сам реестр, ни `PromptForm`.
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
  `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, P2.1 и
  P2.3, впредь требуется у P3.1 и P4.2 · `docker exec agelto_app` вместо `docker compose exec app`
  — интерполяция `.env` падает на `MINIO_ROOT_PASSWORD` (P2.2, P2.3, P2.4) · env-префикс
  (`-e DB_DATABASE=agelto_test …`) на шаге `config:cache` — иначе страж изоляции прерывает прогон
  (P2.3) · `pint --dirty` автофикс — точечными Edit-правками (host-запуск заблокирован
  permission-гейтом сессии; P2.4 так починил два `ordered_imports`) · `phpstan-baseline.neon`
  правится адресным `sed` по `path:`/FQCN, а не полной регенерацией (P2.4) · гейт «дифф
  перемещённых файлов» требует передавать `git diff -M` СТАРЫЕ и НОВЫЕ пути вместе (P2.4)
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к
  `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · канонизация
  D89-исхода для гейтов-сторожей инварианта · правка escape-hatch `plan-exec.md` под
  `topology.json` (D98) — репозиторий `swissknifeman`, вне этого плана · раскладка DTO сметы под
  сегмент `Analyzers\Estimate` — решает P3.5 · подкаталоги внутри `Analyzers/Estimate` (раскладка
  оставлена плоской) · `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана
  сметы (P5)
- open_questions: **новый, в границах P2.5** — типонезависимый резолв значения `purpose` в строку→
  объект (`llm_calls` не несёт `document_type`; реестр §5.2 такого метода не имеет): композиция
  поверх `purposesFor()` по всем типам ИЛИ новая D-запись, расширяющая замороженный §5.2. Решает
  `plan-design`. Остаются два гейта владельца впереди (P3.1, P4.2). Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
