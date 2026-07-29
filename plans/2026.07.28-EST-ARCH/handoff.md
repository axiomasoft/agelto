# HANDOFF — 2026-07-29 — after P1.5

**Next:** `run-items: task:plan-run 2026.07.28-EST-ARCH P1.6` — единый контур обучения/боевого
разбора: ось площадки (D91), прямая связь документа с эталоном, сущность прогона с терминацией
(D93).

| Параметр | Значение |
|:--|:--|
| Model class | implementation (`sonnet`) |
| Effort | high |
| Capabilities | — |
| Context | continue (`/clear`) — ручной item |
| Суть | Правит `prc_platforms` (D91, РОВНО две колонки + одна строка данных, единственное поимённое исключение из неприкосновенности crawling-контура), заводит `AnalysisRun`/`prc_ai_analysis_runs`, переводит миграцию вызовов на новый путь после P1.5 (D96) |

````
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P1.6
````

`Exec = manual` (§3 Routing: `implementation/high`), поэтому модель и effort ставятся СЕССИЕЙ, а
`plan-run` сверяет фактический маршрут инвертированным гейтом (§9) и обязан напечатать фактическую
пару «класс/effort» в отчёте и в Update Log. Гейта владельца у P1.6 нет — три гейта владельца,
оставшиеся в плане, стоят на P2.1, P3.1 и P4.2 (`roadmap.md`).

**Правило исполнения гейтов (D89 + трактовка владельца 2026-07-29) — читать ДО первого коммита
ЛЮБОГО item'а.** Каждая строка `Validation`, являющаяся греп/`git`-командой, прогоняется на
`<base>` и печатается в Completion Notes рядом с выводом «после». Исходы: на `<base>` красный →
норма; на `<base>` уже зелёный → эскалация §10 до написания кода; зелёный недостижим в границах
`Scope` → эскалация §10. У P1.6 такие гейты названы поимённо (Validation 6б); Validation 5 там же
помечен СРАВНИТЕЛЬНЫМ (сверяет «до»/«после», красный исход тоже доказывается мутацией).

**Правило D94:** боевые LLM-прогоны запускает ВЛАДЕЛЕЦ. Агент пишет код и тесты (LLM в тестах —
только `FakeLlmProvider`); item, чья Validation требует боевого вызова, закрывается с записью
«прогон за владельцем» и неисполненным не считается. Прямо касается P5.2 и любых
`estimate:bench:*`/`estimate:eval:*` на живом провайдере.

**Инфраструктурное ограничение ЭТОЙ раскладки репозитория (найдено на P1.5, актуально и для P1.6):**
`packages/task/lib` (`task.task_contract`/`task.route_evidence`) и
`${CLAUDE_PLUGIN_ROOT}/scripts/plan-lint.py` НЕ доступны из `agelto` (репозиторий не несёт их,
`CLAUDE_PLUGIN_ROOT` не задан в среде исполнения) — Pre-mutation/Pre-final гейт `plan-exec.md`
п.2/п.7 и `plan-lint` фиксируются как «недоступен» (fail-closed текстом, не молчаливым `allow`),
закрытие опирается на публично воспроизводимые факты самой `Validation` item'а (D77). Это
ограничение среды, не решение исполнителя — называть его в Completion Notes каждого следующего
item'а, пока раскладка не изменится.

**Done:** P1.5 закрыт (🟠 Done with deviations) — item-коммит `5d23626`, `<base>=ddfa9df`. LLM-
транспорт выделен в домен `Llm`:

- **4 таблицы** переименованы в исходных create-миграциях (D20) + `git mv` файлов на целевые имена
  (D96): `prc_ai_providers→llm_providers`, `prc_ai_routes→llm_routes`, `prc_ai_prompts→llm_prompts`,
  `prc_ai_llm_calls→llm_calls` (партиции, индексы, FK, check-constraint — все под новым именем).
- **Модели/фабрики/репозитории** — `App\Models\Llm\{Provider,LlmRoute,Prompt,LlmCall}`,
  `Database\Factories\Llm\**`, `App\Repositories\Llm\{LlmRouteReadRepository,PromptReadRepository,
  AiCallStoreRepository}` (`AiRoute→LlmRoute` переименован вслед за моделью — карта `naming-canon.md`
  класс 6; `AiCallStoreRepository`/`PromptReadRepository` — только переезд неймспейса, переименование
  первого отдано P1.7).
- **Filament-ресурс маршрута** — `AiRoutes/AiRouteResource` → `LlmRoutes/LlmRouteResource` (каталог +
  класс + `Pages`/`Tables`/`Schemas` переехали, вложенные классы имя не меняют).
- **~50 call-site'ов/use-импортов** правлены по FQN (`namespace-refactor-safety`); канон четырёх
  доменных префиксов синхронизирован в `docs/03_Dev/{architecture,database,llm-providers}.md` и
  `.ai/guidelines/architecture.md`; `phpstan-baseline.neon` регенерирован (D58 — три величины
  275/358/гистограмма НЕ выросли, диф только `path:`/FQCN).
- **Тесты** — полный сюит зелёный: 738 passed, 1 skipped (тот же, что и до item'а), 2607 assertions.
- **🟠 (Known Deviations, два material-пункта):** (1) `config/partitioning.php` — ключи
  `tables`/`retention` переименованы вслед за таблицей (не в `Files`, но функционально обязательно —
  иначе pg_partman регистрирует несуществующее старое имя); (2) `app/Models/Procurement/Ai/
  PromptSuggestion.php` — добавлен `use App\Models\Llm\LlmCall;` (implicit same-namespace ссылка, не
  ловится FQN-грепом, найдена phpstan). Плюс НАЙДЕННЫЙ И ПОЧИНЕННЫЙ (не предусмотренный ТЗ) баг:
  осиротевшая регистрация `prc_ai_llm_calls` в `partman.part_config` переживает `migrate:fresh` (`pg_partman`
  в отдельной схеме) и рвёт `partman.run_maintenance()` — обнаружено первым прогоном полного сюита
  (`MaintainPartitionsTest`), починено `pruneOrphanedRenamedEntry()` внутри уже названного в `Files`
  `..._100047_setup_pg_partman.php`.
- Полные факты, все 11 Validation с числами «до/после» на `ddfa9df`/`5d23626` — `phases/P1.md` P1.5
  Completion Notes / Known Deviations.

**Remaining:** 32 items ⬜ Not started, 5 🟢/🟠 (P1.1–P1.5). Фаза P1 — 5/8. Порядок: P1.6–P1.8 →
P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и три оставшихся гейта владельца
(P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` (схемы, статусы, DoR) ·
`plans/2026.07.28-EST-ARCH/plan.md` §2 (правила исполнения, включая исключение D91) и §5
(**D1–D96**) · **`root/naming-canon.md`, `root/document-type-axis.md` (§6.3 в редакции D95),
`root/document-contour.md` — ЗАМОРОЖЕНЫ, имена и схема берутся ТОЛЬКО оттуда** ·
`research/02-schema-impact-map.md` — почему в `Files` P1.4/P1.6 стоит именно этот список ·
`roadmap.md` · `phases/P1.md` (P1.5 Completion Notes + четыре блока аудита в конце файла) ·
`brief/{00-brief,01-refinements}.md` · `open-questions.md`.

**Open risks:**
- **Инфраструктурный разрыв task_contract/plan-lint** (см. блок выше) — сохраняется для ВСЕХ
  следующих items, пока раскладка репозитория не получит `packages/task/lib`/`CLAUDE_PLUGIN_ROOT`;
  называть явно в каждом Completion Notes, не молчать.
- **`config/ai.php` несёт 2 докблочных упоминания старых имён таблиц** (`prc_ai_routes`,
  `prc_ai_llm_calls`, чисто комментарии) — не гейтится P1.5 (не в `Files`, тот же класс, что
  P3.6/P7.3), правится при следующем касании файла.
- **P1.6's `<base>` — это `5d23626`, не `ddfa9df` и не `4094d37`.** Числа «до» в ТЗ P1.6 (Validation
  на `4094d37`) сняты ДО P1.4/P1.5 — исполнитель ОБЯЗАН перепроверить на своём фактическом `<base>`
  (D66), особенно ссылку на миграцию вызовов (`P1.6 ссылается на миграцию вызовов уже по НОВОМУ
  пути llm_calls`, Implementation Rule 7 P1.5).
- Три строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) — P1.5 добавила четвёртую (домен
  `Llm`). Боевой прогон P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.4, впредь
  требуется у P1.6 (Validation 5, 6б), P2.1, P3.1, P4.2 · `pint --dirty` автофикс — точечными
  Edit-правками по diff'у `--test` (host-запуск заблокирован permission-гейтом сессии, P1.5)
- deferred: ревизия формы оставшихся гейтов на этапе дизайна — снята D89 · BigQuery-адаптер
  биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная
  дупликация `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64,
  включая четвёртый домен `Llm` от P1.5) · общий инвариант «во всём сервисном слое нет прямых
  обращений к `LlmCall`» в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (переименовывается только ресурс маршрута,
  D96) · expression-индексы по `type_profile` — не заводятся до 10 000 строк реестра (§6.4) ·
  эксплуатационный отбор «какие документы пускать в дорогой разбор» — вне схемы (D92) ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` — правится при следующем
  касании промтов · `config/ai.php` докблочные упоминания старых имён таблиц — правятся при
  следующем касании файла (P1.5)
- open_questions: в границах плана — НЕТ. OQ-1…OQ-14 закрыты. Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) — тратит реальные деньги, несёт два
  гейта владельца
