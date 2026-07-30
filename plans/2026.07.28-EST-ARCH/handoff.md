# HANDOFF — 2026-07-30 — after P3.3

**Next:** `run-items: task:plan-run 2026.07.28-EST-ARCH P3.4` — Routing P3.4 = `implementation/high`,
`Exec = manual`; сессия уже на `sonnet/high` (тот же тир) — продолжение без ритуала `/model`+`/effort`.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Переезд пяти сметных групп файлового канона промтов в `resources/estimates/ai/prompts/**`, обнаружение ОБОИХ корней по D32 в `PromptSeeder`-владельце и `PromptPatchApplier` |

```
/task:plan-run 2026.07.28-EST-ARCH P3.4
```

Маршрут `inherited-plan` (§9 `plan-protocol`): модель и effort ставятся СЕССИЕЙ по строке §3
Routing (`implementation/high`, `Exec = manual`), исполнитель обязан напечатать фактическую пару
«класс/effort» в отчёте и в Update Log.

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — терминальна.
P3.1 🟢 (`143d587`) — карта сервисного слоя утверждена владельцем. P3.2 🟠 (`befeee9`) — шесть
кросс-документных команд переведены на сервисы, `prompts:suggest` оставлена P3.3 (D108).
**P3.3 🟠 (`35d8a7b`) — двенадцать сметных команд переведены `git mv` в
`app/Console/Commands/Procurement/Analyzers/Estimate/` и переименованы `ai:* → estimate:*`
(каталог `app/Console/Commands/Ai/` больше не существует).** Логика вынесена на сервисы карты P3.1:
единая форма отказа (`OperationFailed` + 6 наследников), сейм прогресса (`ProgressReporter`/
`ProgressTick`/`ConsoleProgressReporter`), общий `FixtureCaseResolver` устранил 39-строчный дубль
резолва между `eval:diff`/`prompts:suggest` (D108), восемь кросс-документных сервисов `Fixture\**`,
репозитории убрали прямой Eloquent из команд. Оба поведенческих изменения D111 (отчёт корпуса,
дефолт `--out` бенчмарка) идут через новый `config('ai.fixtures.reports_dir')`. Канарейка периметра
ядра arch-теста пересчитана 70→73 (ровно три новых кросс-документных файла, состав неймспейсов
§6.2 НЕ расширен — решение о расширении остаётся за P3.5, D112/OQ-13). `phpstan-baseline.neon`
регенерирован под гейтом трёх величин D58 (записи 254→249, сумма `count:` 327→318, гистограмма
`identifier:` — ни один бакет не вырос). Полный сюит зелёный: **787 passed, 1 skipped
(3143 assertions)**. Пять Known Deviations (форма Validation 2/2а/4а — гейт измеряет не совсем то,
что предполагало ТЗ, факт исполнения при этом зелёный; два файла вне поимённого `Files` —
`GoldCorpusBuilder.php` по авторитету карты §3/§4, `Analysis.php` — одна строка `@property`; свойство
`Analysis::$result` сознательно НЕ документировано, чтобы не расфризивать вне-scope
`ExtractionOrchestrator.php`) — подробности в `phases/P3.md` P3.3 Completion Notes.

**Remaining:** 20 items ⬜ (P3 ×3 [P3.4–P3.6] → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Гейт владельца
впереди один — P4.2. Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D112**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`, `root/service-layer-map.md`** —
правятся ТОЛЬКО новой D-записью со `supersedes` · `findings/commands-and-layout-2026-07-29.md` ·
`roadmap.md` · `open-questions.md` (OQ-13) · `phases/P2.md` (закрыта) · `phases/P3.md`.

**Якоря фактов этого захода** (сняты на `35d8a7b`, воспроизводимы на нём):
`ls app/Console/Commands/Procurement/Analyzers/Estimate/*.php | wc -l` — **12** ·
`ls app/Console/Commands/Ai/` — `No such file or directory` ·
`docker exec agelto_app php artisan list --format=json | python3 -c "...startswith('estimate:')..."` → **12** ·
`...startswith('ai:')` → **6** ·
`grep -rn "ai:eval\|ai:gold\|ai:fixtures\|ai:bench\|ai:dataset\|ai:prompts:suggest" app/ tests/ resources/ database/ config/ routes/` → пусто (на `1fc27d3` — 121 в 32 файлах) ·
`grep -n "toHaveCount" tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php` → **73** ·
`docker exec agelto_app php artisan test` → **787 passed, 1 skipped (3143 assertions)**.

**Lint:** `plan-lint --baseline HEAD plans/2026.07.28-EST-ARCH` — прогоняется в коммите этого
захода (после перезаписи этого файла).

**Open risks:**
- **Окно без машинного гейта D14 между P3.3 и P3.5** (D112/OQ-13): восемь новых кросс-документных
  сервисов `App\Services\Procurement\Fixture\**` не попадают в периметр §6.2 до решения P3.5.
  Окно названо и ограничено двумя items; закрывается Validation 6а P3.5 в любом из двух исходов.
- **Периметр ядра §6.2 — счётчик-канарейка, не список руками:** было 70 на `1fc27d3`, стало 73 на
  `35d8a7b` (Contracts\ProgressReporter, Contracts\Dto\ProgressTick, Screening\ScreeningEvalRunner).
  P3.4–P3.5 добавят/переместят ещё — сверять число построчно при закрытии КАЖДОГО item'а.
- **`app/Pulse/Cards/LlmSpendCard.php:17` цитирует устаревший FQN** `App\Console\Commands\Ai\CostReportCommand`
  (P3.2 переехала эта команда в `Procurement\Ai\`) — чинит P3.6 вместе с двумя файлами `docs/03_Dev/ai/`.
- **`ai:storage:lifecycle` и `ai:tools:doctor` — логически спорные имена** (предъявлены владельцу
  при закрытии P3.1, решение — оставить). Кандидат в P7.3/P7.4.
- **Ключ `'estimates'` в ядре гейт (г) arch-теста P2.6 не ловит** — защита только докблоком §1.5
  инвариант 2 и код-ревью.
- **`PromptCritic` вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия извлечения»
  (D102) arch-тестом не защищён.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **`docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`**
  несут устаревшие пути перемещённых классов — чинит P3.6 вместе с `LlmSpendCard.php` (см. выше).
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE`** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`.
- **`docker compose exec app` недоступен в этой среде** — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` из `plan-exec.md` не резолвится в этой раскладке** — рабочий путь
  `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.9.0/lib`.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`).
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7.
- **Форма Validation-строк 2/2а/4а P3.3 шире факта, который она измеряет** (git-similarity-index
  недооценивает переименования с потерей >50% контента; грепы по `App\Console\Commands\Ai`/
  `2026.07.26-EST-TRAIN` ловят легитимные посторонние упоминания) — подробности и обоснование
  «факт исполнения зелёный» см. Known Deviations P3.3. Полезно как прецедент для авторов будущих
  Validation-строк на переезды со срезкой логики.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон
  P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (D100) · `docker exec agelto_app` вместо `docker compose exec
  app` · env-префикс на шаге `config:cache` · `pint --dirty` автофикс — с ХОСТА (не из контейнера,
  Execution Rules §2) · `phpstan-baseline.neon` правится адресным удалением осиротевших записей
  ПЕРЕД `--generate-baseline` (`reportUnmatchedIgnoredErrors: true`, D71) · `PYTHONPATH` через путь
  плагина `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` · **новое (P3.3): Collection-generic
  инвариантность PHPStan** — при пересечении сервисной границы с union-типизированным вложенным
  array-shape внутри `Collection<int, T>` объявлять `T` БЕЗ параметра (bare `Collection`) там, где
  точное совпадение объявленного и фактического типа недостижимо без искусственного сужения бизнес-логики
- deferred (карта P3.1 §12): транзакции вокруг перенесённой многомодельной записи · полный
  read/store-split `FixtureStoreRepository` · объединение датасетного и gold-путей создания рабочей
  копии (D57) · общая константа маски json-флагов · расхождение «`ini_set` из конфига против
  хардкода» между `GoldBuildCommand`/`GoldImportCommand` (теперь `GoldCorpusBuilder`/
  `WorkingCopyProvisioner`) · переезд каталогов сервисов `Gold`/`Bench`/`Prepare` (D31) ·
  `FormatBenchRunner:275` третья частичная копия сброса `Analysis` — кандидат в отдельный item
  после P3
- deferred (ранее): BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1
  боевым прогоном (D52) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · переезд экранов транспорта
  из `app/Filament/Resources/Procurement/Ai/**` (D96) · expression-индексы по `type_profile` ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  реопен-кандидат Validation 5 P2.5 (`\b`-форма) · перенос сравнения shadow-пары в
  `prc_ai_eval_results` · новый `plan-design finish` под изменённый контракт фазы P2
- open_questions: **OQ-13** (`open-questions.md`, статус `Exploration`) — входят ли восемь новых
  кросс-документных каталогов `Fixture\**` (плюс `Support\Files`, `Exceptions\Procurement`) в
  периметр §6.2 контракта P2.1; решает P3.5 проверкой (D112). Остался один гейт владельца — P4.2.
  Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
