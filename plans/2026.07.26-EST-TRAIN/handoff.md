# HANDOFF — 2026-07-27 — after P3.6

**Next:** P3.6 закрыт 🟠 — батч **B2 (P3.4–P3.6) терминален**. Последний item фазы P3 —
**P3.7** (OCR сканов: представление + замер потолка движка), `Exec = manual`,
`implementation/high` — эта команда (`plan-exec`, пин sonnet/medium) НЕ имеет права его
запускать (Routing-гейт §2). P3.7 несёт вердикт владельцу по потолку OCR (вход в решение о
table-structure движке) — запускать через `/model opus` + `/effort high` +
`/task:plan-run 2026.07.26-EST-TRAIN P3.7`, либо `/task:plan-design` если объём решения того
требует.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — предписано Routing (замер потолка движка + вердикт владельцу, не рутина) |
| Context | continue (/clear) — ручной item |
| Суть | `OcrConverter`+`ScanStrategy` (представление скана) + ЗАМЕР потолка локального OCR по методике, фиксируемой ДО прогона, вердикт владельцу |

```
ЗАПУСК ВРУЧНУЮ: opus/high — /task:plan-run 2026.07.26-EST-TRAIN P3.7
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1–P3.6 — все 🟠 Done with
deviations (item-коммиты `9ea6147`/`54b6d02`/`5a1d90f`/`3c0755a`/`d1ac74d`/`044bc8d`): контракт
представлений, системные бинари в едином образе, `GsfxStrategy`, `LibreOfficeConverter`+
`WordStrategy`+LibreOffice-фолбэк `SpreadsheetStrategy`, `TextGridRenderer` (`text_grid`),
`ArchiveExtractor` (zip/7z/rar). Батч B2 (P3.4–P3.6) — терминален, все три конвертера поверх
готового контракта представлений (P3.1) и готовых бинарей (P3.2). Реальные эталоны корпуса
(`NEG-DOCX-CONTRACT-001`, `EST-XLSX-VOR-001`, `EST-XLSX-KS2-001`, `EST-XLSX-KS2-001`) дали
корректные представления (кириллица без квадратов, `text_grid` меньше PDF на реальном эталоне,
два представления за проход `SpreadsheetStrategy`); архивы в корпусе `estimates/files` реально
отсутствуют (`Why` P3.6) — 7z-путь покрыт синтетическими архивами, собранными реальным `7z` в
тестах, RAR-путь ЧАСТИЧНО (нет свободного RAR-энкодера, D24).

**Remaining:** фаза P3 — 1 item (P3.7, manual/opus-high, вердикт владельцу по потолку OCR).
После P3.7 фаза P3 терминальна (все 7 items 🟠/🟢) → `/task:plan-close 2026.07.26-EST-TRAIN P3`.
Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1–P3.6) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5** недостижим по букве — фактический счёт `4`
  (все в `database/migrations/**`); ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1`.
- **P3.2 Validation-3** в буквальной форме (`7zz i | head -3`) недостижима — фактический бинарь
  `7z`; P3.6 подтвердил тот же факт для `sevenzip_bin`/`unar_bin` через `ai:tools:doctor`.
- **`GsfxPosition::$coefficients`** для `EST-GSFX-LSR-001` всегда `[]` (P3.3) — без изменений.
- **`.gsfx`-контейнер несёт слой `ImplemActs`**, не только смету (P3.3) — сознательно не
  моделируется, без изменений.
- **`phpstan-baseline.neon` правился вне `Files` уже ШЕСТЬ раз подряд** (P3.1–P3.6) — тот же
  структурный пробел (`Document`/`DocumentVersion` без `@property`). Рабочий приём регенерации
  (временный `phpstan.neon` без `includes: phpstan-baseline.neon` + абсолютные пути +
  `--generate-baseline=/tmp/…` + построчная сверка `message:` + `cp` на хост) стабильно
  воспроизводится третий раз подряд без потери старых записей.
- **RAR-путь `ArchiveStrategy` покрыт ЧАСТИЧНО** (P3.6, Known Deviations): `archive_unsupported`
  проверен реально, `password_protected`/дети/path-traversal для rar — НЕТ (нет свободного
  RAR-энкодера в окружении, D24). Если корпус когда-либо принесёт реальный `.rar` — прогнать
  `ArchiveStrategy` на нём вручную (методика P3.4/P3.5 — `tinker` + реальный файл).
- **`lsar` вызывается литералом**, не заведён `config('ai.tools.*')` (P3.6) — если потребуется
  переопределение пути в проде, добавить `ai.tools.lsar_bin` отдельным D#.
- **Формат `text_grid` зафиксирован докблоком `TextGridRenderer`** — переизобретать в P6 нельзя.
  `setReadDataOnly` сознательно НЕ используется (P3.5 Known Deviations).
- **`unprocessable(conversion_failed/password_protected/archive_unsupported)` теперь терминален
  изнутри стратегии** (P3.4/P3.6) — P3.7 (OCR), если тоже переводит исчерпание в терминальный
  исход, сверить единообразие `last_error`.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1–P3.6 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (не в item-коммиты P3.1–P3.6): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Ручные прогоны P3.4/P3.5 (Validation) создали реальные `Document`/`DocumentVersion` в
  DEV-БД (не `agelto_test`) через `tinker` — не убраны, тот же класс побочного артефакта, что
  P3.1–P3.3.
- `phases/P1.md` несёт `plan-lint`-ложноположительный WARN (буквальное `[NEEDS CLARIFICATION]` в
  тексте DoR-пункта) — P1 не трогался.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны, иначе падает на `MINIO_ROOT_PASSWORD`); `REDIS_PORT=6391`
  (`6379` занят `sova_redis`) — следующей сессии учитывать тот же env-оверрайд.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов (`--env-file .env --env-file .env.docker`);
  `REDIS_PORT=6391`; `make artisan`/`make ps` конфликтуют с файлом `artisan` в корне — звать
  `docker compose … exec app php artisan …` напрямую; запись в bind-mounted пути из контейнера
  требует ACL — `phpstan --generate-baseline`/`pint --dirty` пишутся с ХОСТА, либо (для baseline)
  временный `phpstan.neon` без `includes: phpstan-baseline.neon` + `--generate-baseline=/tmp/…` +
  `cp` на хост; git-диффы внутри контейнера требуют `git config --global --add safe.directory
  /var/www/html` под `www-data` (не персистентно между пересборками).
- deferred: тест-двойники цепочки стратегий живут в самом `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (дифф вне `Files`, унаследовано от P3.1). Отдельного
  `WordStrategyTest.php` нет — кейсы `WordStrategy` живут в `SpreadsheetStrategyTest.php`.
  RAR-кейсы, требующие сборки архива, — deferred до появления свободного энкодера/реального
  `.rar` в корпусе (P3.6).
- open_questions: OQ-3, OQ-4 — без изменений.
