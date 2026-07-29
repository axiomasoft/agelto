# HANDOFF — 2026-07-29 — after P1.4

**Next:** `run-items: task:plan-run 2026.07.28-EST-ARCH P1.5` — вынос LLM-транспорта в домен `Llm`
(переименование четырёх таблиц + файлов миграций, перенос моделей/фабрик/репозиториев, D96).

| Параметр | Значение |
|:--|:--|
| Model class | implementation (`sonnet`) |
| Effort | high |
| Capabilities | — |
| Context | continue (`/clear`) — ручной item |
| Суть | Переезд `prc_ai_providers`/`prc_ai_routes`/`prc_ai_prompts`/`prc_ai_llm_calls` → `llm_*` (таблицы, файлы миграций, модели, фабрики, репозитории, call-site'ы) |

````
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P1.5
````

`Exec = manual` (§3 Routing: `implementation/high`), поэтому модель и effort ставятся СЕССИЕЙ, а
`plan-run` сверяет фактический маршрут инвертированным гейтом (§9) и обязан напечатать фактическую
пару «класс/effort» в отчёте и в Update Log. Гейта владельца у P1.5 нет — три гейта владельца,
оставшиеся в плане, стоят на P2.1, P3.1 и P4.2 (`roadmap.md`).

**Правило исполнения гейтов (D89 + трактовка владельца 2026-07-29) — читать ДО первого коммита
ЛЮБОГО item'а.** Каждая строка `Validation`, являющаяся греп/`git`-командой, прогоняется на
`<base>` и печатается в Completion Notes рядом с выводом «после». Исходы: на `<base>` красный →
норма; на `<base>` уже зелёный → эскалация §10 до написания кода; зелёный недостижим в границах
`Scope` → эскалация §10. Гейт над СОЗДАВАЕМЫМ артефактом (на `<base>` красного не бывает вовсе) —
фальсифицируемость мутацией (копия артефакта вне репо + инъекция нарушения + красный вывод в
Completion Notes), решение владельца 2026-07-29, применено в P1.1–P1.4. У P1.6 такие гейты названы
поимённо (Validation 6б); Validation 5 там же помечен СРАВНИТЕЛЬНЫМ (сверяет «до»/«после», красный
исход тоже доказывается мутацией).

**Правило D94:** боевые LLM-прогоны запускает ВЛАДЕЛЕЦ. Агент пишет код и тесты (LLM в тестах —
только `FakeLlmProvider`); item, чья Validation требует боевого вызова, закрывается с записью
«прогон за владельцем» и неисполненным не считается. Прямо касается P5.2 и любых
`estimate:bench:*`/`estimate:eval:*` на живом провайдере.

**Done:** P1.4 закрыт (🟢) — item-коммит `1a86401` (плюс предваряющий `35b9748`, см. Known
Deviations phases/P1.md), `<base>=4094d37`. Схема восьми AI-таблиц под контракт
`document-type-axis.md`:

- **Ось типа документа** — check-constraint из единственного носителя `AiDocumentType::values()` на
  всех восьми таблицах; сентинел кросс-документного назначения — новый `App\Enums\Llm\DocumentTypeScope::Any`.
  Составные ключи D7: `llm_prompts`(`prc_ai_prompts`) → `(document_type, key, version)`,
  `llm_routes`(`prc_ai_routes`) → `+ shadow`.
- **`prc_ai_fixtures`** — `doc_type`→`document_type NOT NULL`, `+owner_document_type NOT NULL`,
  `+title NOT NULL`; пять профильных полей (`class`/`form`/`expected_estimates`/`expected_rows`/
  `gold_source`) уехали в `type_profile jsonb` — читаются типизированными `Fixture::profile*()`
  read-методами (не top-level property), без индексов (§6.4).
- **`prc_document_types`** снесён (D16) — миграция/модель/фабрика удалены, три комментария-ссылки
  переписаны дословно.
- **Расход на `prc_ai_analyses`** — 5 nullable-колонок (значения пишет P4).
- **Вынужденная синхронизация** — ~60 файлов: модели/фабрики (профильные поля собираются в
  `type_profile`, не плоско), сидеры + front-matter 9 файлов промтов (`document_type` обязателен),
  `PromptPatchApplier`/`PromptsSyncCommand` (тот же канон), `GoldBuildCommand`/`EvalRunCommand`/
  `FormatBenchRunner`/`FixturesVerifyCommand` (типизированные read-методы вместо top-level полей),
  три писателя `prc_ai_dataset_examples` (`document_type` явно — `DatasetImportCommand` получил
  новую опцию `--document-type=estimate`), `PromptForm`/`AiRouteForm` (`Select` вместо `Textarea`),
  `ViewAnalysis` (коррекция недоступна без `document_type` анализа — Rule 12), `estimates/manifest.json`
  (24 записи регенерированы разовым скриптом).
- **Тесты** — 12 новых негативных (check-constraint на 8 колонок + NULL + дубль маршрута,
  `DocumentTypeAxisTest`) + сентинел-тест (`DocumentTypeScopeTest`, Validation 18); синхронизировано
  10 существующих файлов; 1 тест снят (сценарий структурно недостижим после `NOT NULL`).
- **`phpstan-baseline.neon` (D71)** — вычитающая дельта: 5 записей `Analysis::$document_type`
  осиротели после `@property` на модели (пред-существующий долг в 4 файлах ВНЕ `Files` этого item'а
  — снят как побочный эффект докблока, не как самостоятельная правка тех файлов); messages 280→275,
  сумма `count:` 366→358, `property.notFound` 236→231 — все три величины уменьшились.
- Полные факты, числа «до/после» на `4094d37`, обоснование негативной классификации 13 фикстур
  корпуса — `phases/P1.md` P1.4 Completion Notes / Known Deviations.

**Remaining:** 33 items ⬜ Not started, 4 🟢 (P1.1–P1.4). Фаза P1 — 4/8. Порядок: P1.5–P1.8 →
P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и три оставшихся гейта владельца
(P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` (схемы, статусы, DoR) ·
`plans/2026.07.28-EST-ARCH/plan.md` §2 (правила исполнения, включая исключение D91) и §5
(**D1–D96**) · **`root/naming-canon.md`, `root/document-type-axis.md` (§6.3 в редакции D95),
`root/document-contour.md` — ЗАМОРОЖЕНЫ, имена и схема берутся ТОЛЬКО оттуда** ·
`research/02-schema-impact-map.md` — почему в `Files` P1.4/P1.6 стоит именно этот список ·
`roadmap.md` · `phases/P1.md` (P1.4 Completion Notes + четыре блока аудита в конце файла) ·
`brief/{00-brief,01-refinements}.md` · `open-questions.md`.

**Open risks:**
- **Гейты P1.5/P1.6 против дерева, которого ещё нет.** Числа «до» в ТЗ P1.5 (Validation 10а/10б)
  и P1.6 (6 строк `procurement_id`, одна запись baseline) сняты на `4094d37` — P1.4 уже landed
  (item-коммит `1a86401`), поэтому P1.5's `<base>` — это `1a86401`, не `4094d37`. Числа по самим
  файлам миграций/ресурса P1.5 не должны были сдвинуться (P1.4 их не трогал структурно), но
  исполнитель ОБЯЗАН перепроверить на своём `<base>`, а не принять на веру (D66). Расхождение =
  находка, не помеха.
- **Столкновение двух решений контракта на `prc_ai_corrections`** (анализ может не знать тип, а
  правка обязана) — разрешено на границе записи (`ViewAnalysis` недоступна/отклоняется без
  `document_type`), покрыто тестом НА ГРАНИЦЕ СХЕМЫ (NOT NULL/QueryException), не на границе UI —
  в сюите нет прецедента Livewire/Filament action-теста (Known Deviations P1.4 п. 2).
- **11 из 13 негативов корпуса эталонов получили `document_type=unknown`** при регенерации
  манифеста (два — `contract_draft`/`tech_spec` по прямому свидетельству имени файла) — заметно
  владельцу при следующей сверке корпуса, не влияет на схему/поведение (D14).
- Три строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64). Рычаг `@property` может не
  сработать на будущих items (D63/D53) — на P1.4 сработал, включая для пред-существующего долга.
  Боевой прогон P5.2 — за владельцем (D94). `resources/ai/prompts/README.md` не обновлён под новое
  обязательное поле `document_type` (Pending Work P1.4, тривиальная правка).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейда МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.4, впредь
  требуется у P1.6 (Validation 5, 6б), P2.1, P3.1, P4.2
- deferred: ревизия формы оставшихся гейтов на этапе дизайна — снята D89 · BigQuery-адаптер
  биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная
  дупликация `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) ·
  общий инвариант «во всём сервисном слое нет прямых обращений к `LlmCall`» в план НЕ вводится
  (D70/D79) · переезд экранов транспорта из `app/Filament/Resources/Procurement/Ai/**` — вне D22
  (переименовывается только ресурс маршрута, D96) · expression-индексы по `type_profile` — не
  заводятся до 10 000 строк реестра (§6.4) · эксплуатационный отбор «какие документы пускать в
  дорогой разбор» — вне схемы (D92) · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` — правится при следующем касании промтов
- open_questions: в границах плана — НЕТ. OQ-1…OQ-14 закрыты. Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) — тратит реальные деньги, несёт два
  гейта владельца
