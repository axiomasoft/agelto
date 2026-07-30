# HANDOFF — 2026-07-30 — after P3.2

**Next:** `/task:plan-design 2026.07.28-EST-ARCH P3.3` — P3.3 не готов к исполнению: Pending Work
P3.1 п.1 (следствие D108) требует правки ЕЁ ТЗ ДО старта — `Validation 3` ждёт 11
`estimate:`-команд (стало 12 после переклассификации `prompts:suggest`), `Validation 4` ждёт 7
`ai:`-команд (стало 6), `Scope Included`/`Files` не несут `PromptsSuggestCommand.php`
(`ai:prompts:suggest` → `estimate:prompts:suggest`, `PromptSuggestionRunner`). Исполнителю чинить
своё же ТЗ запрещено (`plan-protocol`) — правит `plan-design`.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — design-item, синхронизация ТЗ с зафиксированным D108 |
| Context | NEW SESSION — шаг-не-item |
| Суть | Привести `Scope Included`/`Files`/`Validation 3-4` P3.3 в соответствие с D108 (перевод `prompts:suggest` на `estimate:*`) |

```
/task:plan-design 2026.07.28-EST-ARCH P3.3
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — терминальна.
P3.1 🟢 (`143d587`) — контракт сервисного слоя утверждён владельцем (детали — предыдущий handoff/
`phases/P3.md` P3.1 Completion Notes).
**P3.2 🟠 (`befeee9`)** — шесть кросс-документных команд (`ai:prompts:sync`, `ai:prompts:apply`,
`ai:caches`, `ai:cost-report`, `ai:storage:lifecycle`, `ai:tools:doctor`) переведены `git mv` в
`app/Console/Commands/Procurement/Ai/` и переписаны на сервисы карты P3.1: `App\Services\Llm\
{PromptCanonReader,PromptCanonSynchronizer}` (владелец разбора канона + `sync()`/`drift()`),
`App\Services\Llm\LlmProviderFactory::forDriver()` (единственный `match` по драйверу — `AiRouteResolver`
и `LlmCacheManager::prune()` делегируют ему), `App\Services\Llm\LlmSpendReporter` +
`App\Repositories\Llm\LlmCallReadRepository`, `App\Services\Storage\MinioLifecycleManager`,
`App\Services\Procurement\Prepare\ToolchainProbe`. `database\seeders\PromptSeeder::run()` =
`$this->synchronizer->sync()`. Новые `PromptStoreRepository` + расширение `PromptReadRepository`
(`findVersion()`, `activeProtected()`). Новые тесты `CachesTest.php`/`PromptsSyncTest.php`.
`ai:prompts:suggest` СОЗНАТЕЛЬНО не тронута (D108 → P3.3).
**Этот заход (`plan-run/sonnet-high`, session-модель владельца, D18):** Validation 1–12 —
детали и числа в `phases/P3.md` P3.2 Completion Notes; полный сюит 783 passed/1 skipped на
item-коммите; phpstan три величины: записи 254→254, сумма `count:` 326→327 (**+1**, обоснование
в Known Deviations), identifier-бакеты без роста; `pint --dirty --test` зелёный (21 файл).

**Remaining:** 21 items ⬜ (P3 ×4 [P3.3–P3.6] → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Гейт владельца
впереди один — P4.2. Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D108**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`, `root/service-layer-map.md`** —
правятся ТОЛЬКО новой D-записью со `supersedes` · `findings/commands-and-layout-2026-07-29.md` ·
`roadmap.md` · `phases/P2.md` (закрыта) · `phases/P3.md`.

**Lint:** `plan-lint --baseline HEAD plans/2026.07.28-EST-ARCH` — прогоняется в
bookkeeping-коммите этого захода (после перезаписи этого файла).

**Open risks:**
- **P3.3 не готов к старту** (см. Next) — Pending Work P3.1 п.1, следствие D108: числа
  Validation 3/4 и `Files`/`Scope Included` разошлись с картой. Правит `plan-design`.
- **`FormatBenchRunner:275` — третья, частичная копия сброса `Analysis`** (карта §6 сводит только
  две из `EvalRunCommand`). Кандидат в отдельный item после P3.
- **`ai:storage:lifecycle` и `ai:tools:doctor` — логически спорные имена** (предъявлены владельцу
  при закрытии P3.1, решение — оставить). Кандидат в P7.3/P7.4.
- **Ключ `'estimates'` в ядре гейт (г) arch-теста P2.6 не ловит** — защита только докблоком §1.5
  инвариант 2 и код-ревью.
- **`PromptCritic` вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия извлечения»
  (D102) arch-тестом не защищён.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`.
  **Третий кандидат добавлен этим заходом:** `app/Pulse/Cards/LlmSpendCard.php:17` (докблок цитирует
  старый FQN `App\Console\Commands\Ai\CostReportCommand`) — вне `Files`/Scope P3.2, чинит P3.6.
- **Периметр ядра §6.2 (`CoreKnowsNoDocumentTypeTest`) — счётчик-канарейка, не список руками:**
  62→70 этим заходом (восемь новых файлов в `App\Services\Llm`/`App\Repositories\Llm`, ПРОВЕРЕНО
  построчно). P3.3–P3.5 добавят/переместят файлы в те же неймспейсы — при закрытии сверять число
  заново, не считать константой.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE`** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`.
- **`docker compose exec app` недоступен в этой среде** — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` из `plan-exec.md` не резолвится в этой раскладке** — рабочий путь
  `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (подтверждён
  фактическими прогонами на P2.6, P3.1, P3.2: `allow`/`allow`).
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`).
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · `config/ai.php` несёт докблочные упоминания старых имён таблиц · строки Pending
  Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (D100) · `docker exec agelto_app` вместо `docker compose exec
  app` · env-префикс на шаге `config:cache` · `pint --dirty` автофикс — точечными Edit-правками ·
  `phpstan-baseline.neon` правится адресным удалением осиротевших записей ПЕРЕД `--generate-baseline`
  (`reportUnmatchedIgnoredErrors: true`, D71 — regen падает, если baseline ссылается на путь
  перемещённого/удалённого файла) · `PYTHONPATH` через путь плагина
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` · дублирование pairKey-вычисления между
  `sync()`/`drift()` устранено методом `pairKeyOf()` — снизило рост baseline с +2 до +1 (P3.2)
- deferred (введено картой P3.1 §12): транзакции вокруг перенесённой многомодельной записи · полный
  read/store-split `FixtureStoreRepository` · объединение датасетного и gold-путей создания рабочей
  копии (D57) · общая константа маски json-флагов · расхождение «`ini_set` из конфига против
  хардкода» между `GoldBuildCommand:54` и `GoldImportCommand:55` · переезд каталогов сервисов
  `Gold`/`Bench`/`Prepare` (D31)
- deferred (ранее): BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1
  боевым прогоном (D52) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · переезд экранов транспорта
  из `app/Filament/Resources/Procurement/Ai/**` (D96) · expression-индексы по `type_profile` ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  `docs/specs/v2/07-carryover-from-v1.md:107` и `CLAUDE.md:60` — P7.3/P7.4 (D104) · реопен-кандидат
  Validation 5 P2.5 (`\b`-форма) · перенос сравнения shadow-пары в `prc_ai_eval_results` · новый
  `plan-design finish` под изменённый контракт фазы P2
- open_questions: незакрытых вопросов В ГРАНИЦАХ плана нет. Остался один гейт владельца — P4.2.
  Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
