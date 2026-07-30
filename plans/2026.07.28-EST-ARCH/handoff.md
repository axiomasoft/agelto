# HANDOFF — 2026-07-30 — after P3.5

**Next:** `/task:plan-exec 2026.07.28-EST-ARCH P3.6` — Routing P3.6 = `implementation/low`,
`Exec = plan-exec`, механическая документарная синхронизация имён/путей, thinking вреден.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — Routing P3.6 `implementation/low`, механическая правка без решений |
| Context | continue (/clear) — ручной item |
| Суть | Синхронизация `.ai/guidelines/**`, `.claude/rules/agelto-reviewer-overrides.md`, трёх локальных скилов и `docs/03_Dev/**` с фактическими именами команд `estimate:*`, путями DTO/промтов и именами таблиц AI-контура (D65) |

```
/task:plan-exec 2026.07.28-EST-ARCH P3.6
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — терминальна.
P3.1 🟢 (`143d587`) — карта сервисного слоя утверждена владельцем. P3.2 🟠 (`befeee9`) — шесть
кросс-документных команд переведены на сервисы, `prompts:suggest` оставлена P3.3 (D108).
P3.3 🟠 (`35d8a7b`) — двенадцать сметных команд переведены `git mv` в
`app/Console/Commands/Procurement/Analyzers/Estimate/` и переименованы `ai:* → estimate:*`.
P3.4 🟢 (`2a140b9`) — пять сметных групп файлового канона промтов переехали `git mv` в
`resources/estimates/ai/prompts/**`, `PromptCanonReader` — единственный владелец двух глобов D32.
**P3.5 🟢 (`f2a4f75`) — DTO/репозиторий AI-подомена разложены по D31.** `Estimate/**` (16) +
`Diff/**` (2) → `App\Dto\Procurement\Analyzers\Estimate\**`; `Gsfx/**` (13) → `App\Dto\Procurement\
Prepare\Gsfx\**`; `Gold/**` (7) → `App\Dto\Procurement\Gold\**`; `Bench` (1) → `App\Dto\Procurement\
Bench\**`; `Fixture/**` (2) → рядом с уже существующим `ItemOutcome`; `Screening`/`Tuning` остались
под `Ai/**` (контракты LLM-вызова). `EstimateStoreRepository` переехал в каталог анализатора.
`App\Services\Llm\AiRouteResolver` → `LlmRouteResolver` (класс+файл+тест+25 потребителей на
`2e7005a`) — строка карты P1.1 класс 6 исполнена. Arch-тест P2.6 указывает на новый путь Estimate
DTO, фальсифицируемость подтверждена probe-импортом (тест красен с probe, зелен без). **OQ-13
закрыт (D113):** `App\Services\Procurement\Fixture` и `App\Support\Files` вошли в периметр §6.2
(счётчик-канарейка 73 → 83), `App\Exceptions\Procurement` — нет (`SplitPoolEmpty.php` тянет
`Analyzers\Estimate\EvalSplitPlanner`). `root/document-analyzer-contract.md` правлен по D33.
`phpstan-baseline.neon` регенерирован — три метрики D58 не выросли (249 записей/318 сумма
count/идентичная гистограмма identifier, до и после). Полный сюит зелёный: **791 passed, 1 skipped
(3214 assertions)**. Known Deviations нет — детали см. `phases/P3.md` P3.5 Completion Notes.

**Remaining:** 18 items ⬜ (P3 ×1 [P3.6] → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Гейт владельца впереди
один — P4.2. Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D113**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md` (§6.1/§6.2/§6.4 правлены P3.5 по
D33/D113 — ИСПОЛНЕНИЕ, не расфриз), `root/service-layer-map.md`** — правятся ТОЛЬКО новой D-записью
со `supersedes` · `findings/commands-and-layout-2026-07-29.md` · `roadmap.md` · `open-questions.md`
(OQ-13 закрыт) · `phases/P2.md` (закрыта) · `phases/P3.md`.

**Якоря фактов этого захода** (сняты на `f2a4f75`, воспроизводимы на нём):
`ls app/Dto/Procurement/Ai/` — **2** каталога (`Screening`, `Tuning`) ·
`grep -rn "Dto\\Procurement\\Ai\\(Estimate|Diff|Gsfx|Gold|Bench|Fixture)" app/ tests/ database/ docs/` → пусто ·
`grep -rn "AiRouteResolver" app/ tests/ database/` → пусто (на `4094d37` — 39 строк/22 файла) ·
`docker exec agelto_app php artisan test --filter=CoreKnowsNoDocumentType` → **3 passed (501 assertions)**, `toHaveCount` **83** (было 73) ·
`docker exec agelto_app php artisan test` → **791 passed, 1 skipped (3214 assertions)** (было 791/1/3214 на `2a140b9` — без изменений, т.к. чистое перемещение) ·
`phpstan-baseline.neon`: записи 249=249, сумма `count:` 318=318 (на `2e7005a`/`f2a4f75`) ·
`docker exec agelto_app ./vendor/bin/phpstan analyse --memory-limit=1G` → **No errors** ·
`./vendor/bin/pint --dirty --test` → зелёный (60 files) после автофикса с хоста.

**Lint:** `plan-lint --baseline HEAD plans/2026.07.28-EST-ARCH` — прогоняется в коммите этого
захода (после перезаписи этого файла).

**Open risks:**
- **`App\Exceptions\Procurement` вне периметра §6.2** (D113) — `SplitPoolEmpty.php` легально тянет
  `Analyzers\Estimate\EvalSplitPlanner`; если эта зависимость когда-нибудь снимется, каталог станет
  кандидатом повторной проверки OQ-13-стиля, но это НЕ открытый вопрос сейчас (OQ-13 закрыт в обоих
  исходах).
- **Периметр ядра §6.2 — счётчик-канарейка, не список руками:** было 73 на `2a140b9`, стало 83 на
  `f2a4f75` (P3.5 добавила Fixture+Support\Files, 10 файлов). P3.6 не трогает код — счётчик менять
  не должен.
- **`app/Pulse/Cards/LlmSpendCard.php:17` цитирует устаревший FQN**
  `App\Console\Commands\Ai\CostReportCommand` (P3.2 переехала эта команда в `Procurement\Ai\`) —
  чинит P3.6 вместе с двумя файлами `docs/03_Dev/ai/`.
- **`ai:storage:lifecycle` и `ai:tools:doctor` — логически спорные имена** (предъявлены владельцу
  при закрытии P3.1, решение — оставить). Кандидат в P7.3/P7.4.
- **`PromptCritic` вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия извлечения»
  (D102) arch-тестом не защищён.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **`docs/03_Dev/ai/feature-card-estimate-extraction.md:13`, `docs/03_Dev/ai/training-loop.md:21`,
  `docs/03_Dev/architecture.md`, `docs/03_Dev/llm-providers.md`, `docs/03_Dev/adr/
  0004-jsonb-for-non-estimate-results.md`, `docs/specs/v2/{04-llm-providers,08-estimate-extraction}.md`,
  `.claude/skills/testing-rules/SKILL.md`** несут устаревшие пути/имена (`AiRouteResolver`,
  `App\Dto\Procurement\Ai\Estimate`) — чинит P3.6 (документарная синхронизация, вне Scope P3.5).
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE`** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`.
- **`docker compose exec app` недоступен в этой среде** — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` из `plan-exec.md` не резолвится в этой раскладке** — рабочий путь
  `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.9.0/lib`.
- **Grep-гейты на литеральный `\`-разделитель FQN требуют учёта окружения:** `grep`/`ugrep` в этой
  среде — в двойных кавычках нужны ЧЕТЫРЕ бэкслеша (`"Ai\\\\Estimate"`), чтобы дойти до grep одним
  литеральным `\`; с двумя `ugrep` либо ошибается «invalid escape», либо не то ищет. Проверять на
  `<base>` перед тем, как доверять «пустому выводу».
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`).
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7.
- **Форма Validation-строк 2/2а/4а P3.3 шире факта, который она измеряет** — подробности см. Known
  Deviations P3.3.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон
  P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ/probe-импортом вместо прогона на
  `<base>` — для гейтов над создаваемым/изменяемым артефактом (D100) · `docker exec agelto_app`
  вместо `docker compose exec app` · env-префикс на шаге `config:cache` · `pint --dirty` автофикс —
  с ХОСТА (не из контейнера, Execution Rules §2) · `phpstan-baseline.neon` — стале `path:`-записи
  переезжающих классов правятся ВРУЧНУЮ на новый путь ДО `--generate-baseline` (иначе
  `reportUnmatchedIgnoredErrors` роняет генерацию с «Invalid entries in ignoreErrors») ·
  `PYTHONPATH` через путь плагина `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` ·
  нормализованное мультисет-сравнение baseline (месседж+count+identifier с учётом ожидаемых
  переименований FQN) — когда phpstan-генератор переставляет записи и построчный `git diff` шумит
  · Collection-generic инвариантность PHPStan (P3.3) · **новое (P3.5): grep-гейт на литеральный
  `\`-разделитель FQN нуждается в ЧЕТЫРЁХ бэкслешах внутри двойных кавычек под `ugrep`** — не трюк,
  а корректная эскейп-арифметика двойных кавычек bash + BRE
- deferred (карта P3.1 §12): транзакции вокруг перенесённой многомодельной записи · полный
  read/store-split `FixtureStoreRepository` · объединение датасетного и gold-путей создания рабочей
  копии (D57) · общая константа маски json-флагов · расхождение «`ini_set` из конфига против
  хардкода» между `GoldBuildCommand`/`GoldImportCommand` · переезд каталогов сервисов
  `Gold`/`Bench`/`Prepare` (D31 — сознательно НЕ переезжают, только их DTO) ·
  `FormatBenchRunner:275` третья частичная копия сброса `Analysis` — кандидат в отдельный item
  после P3
- deferred (ранее): BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1
  боевым прогоном (D52) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · переезд экранов транспорта
  из `app/Filament/Resources/Procurement/Ai/**` (D96) · expression-индексы по `type_profile` ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  реопен-кандидат Validation 5 P2.5 (`\b`-форма) · перенос сравнения shadow-пары в
  `prc_ai_eval_results` · новый `plan-design finish` под изменённый контракт фазы P2
- open_questions: нет открытых в этой фазе (OQ-13 закрыт D113). Остался один гейт владельца — P4.2.
  Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
