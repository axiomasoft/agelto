# HANDOFF — 2026-07-27 — after P3.7

**Next:** P3.7 закрыт 🟠 — **фаза P3 терминальна** (все 7 items 🟠 Done with deviations).
Следующий шаг — не item, а закрытие фазы: `/task:plan-close 2026.07.26-EST-TRAIN P3`
(Phase Handoff в `phases/P3.md`, сверка таблиц, docs-sync, перезапись handoff). Первый item
следующей фазы (P4.1, `Exec = manual`, `implementation/high`) запускается уже ПОСЛЕ закрытия P3.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — сверка таблиц и Phase Handoff по фактам, решений нет |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P3: Phase Handoff, счётчики Status Board, docs-sync, handoff под P4 |

```
/task:plan-close 2026.07.26-EST-TRAIN P3
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). **P3 — терминальна: P3.1–P3.7 все 🟠
Done with deviations** (item-коммиты `9ea6147`/`54b6d02`/`5a1d90f`/`3c0755a`/`d1ac74d`/`044bc8d`/
`685563e`): контракт представлений + цепочка стратегий, системные бинари в едином образе,
`GsfxStrategy`, `LibreOfficeConverter`+`WordStrategy`+LibreOffice-фолбэк, `TextGridRenderer`
(`text_grid`), `ArchiveExtractor` (zip/7z/rar), `ScanStrategy`+`OcrConverter` (`pdf_ocr`/`text`).
P3.7 дал ЧИСЛО потолка локального OCR: **23 корректные строки из 46 размеченных (50,0 %)** —
`EST-PDF-SCAN-001` 39,3 %, `NEG-PDF-DRAWING-002` 66,7 %, мягкая метрика «наименование читается»
89,1 % (`research/04-ocr-ceiling.md`, методика зафиксирована коммитом ДО прогона). Вывод владельцу:
локальный OCR годится как ВХОД для LLM-извлечения и НЕ годится как источник эталона — gold по
сканам остаётся за HITL (P8.3); вход в возможное решение о table-structure движке — это число.

**Remaining:** закрыть фазу P3 (`plan-close`), затем P4 (аудит и правка схемы смет, 5 items,
первый — P4.1 `manual`/`implementation·high`). Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ и Completion Notes P3.1–P3.7) ·
`plans/2026.07.26-EST-TRAIN/research/04-ocr-ceiling.md` (методика, замер, граница применимости) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5** недостижим по букве — фактический счёт `4`
  (все в `database/migrations/**`); ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1`.
- **P3.2 Validation-3** в буквальной форме (`7zz i | head -3`) недостижима — фактический бинарь `7z`.
- **`phpstan-baseline.neon` правился вне `Files` СЕМЬ раз подряд** (P3.1–P3.7) — один и тот же
  структурный пробел (`Document`/`DocumentVersion` без `@property`). Рабочий приём регенерации
  (временный `phpstan.neon` без `includes: phpstan-baseline.neon` + абсолютные пути +
  `--generate-baseline=/tmp/…` + построчная сверка + `cp` на хост) воспроизведён без потерь
  (пропало 0, добавилось 3). Кандидат на отдельный item: `@property`-аннотации моделям.
- **Потолок OCR измерен и низкий (39 % на сметном скане)** — на этом представлении НЕЛЬЗЯ строить
  автоматический gold; `EST-PDF-SCAN-001` остаётся `llm_assisted` с `gold_path = null` (D91), его
  эталон появится только через HITL (P8.3). Решение о table-structure движке — вне плана.
- **`pdf_ocr` 132-МБ эталона весит 133 МБ** и не влезает в `max_request_file_mb: 50` ни одного
  маршрута — для таких документов OCR даёт ценность только представлением `text` (120 тыс.
  токенов) и оконным путём; ячейка матрицы P6 заполнена, решение — за P6.
- **Ветка изображений OCR-ит только картинки с DPI в метаданных** (`ocrmypdf` → `DpiError` иначе);
  состав аргументов зафиксирован ТЗ P3.7, добавление `--image-dpi` требует отдельного D#. Пока
  такие изображения идут в vision-ветку как раньше (документ терминальным не становится).
- **Файл, не прошедший зонд `pdftotext`** (битый xref/не-PDF), OCR не запускает и идёт дальше по
  цепочке — сознательное решение P3.7 (Known Deviations), покрыто тестом.
- **`GsfxPosition::$coefficients`** для `EST-GSFX-LSR-001` всегда `[]` (P3.3) — без изменений.
- **`.gsfx`-контейнер несёт слой `ImplemActs`** (P3.3) — сознательно не моделируется.
- **RAR-путь `ArchiveStrategy` покрыт ЧАСТИЧНО** (P3.6): `password_protected`/дети/path-traversal
  для rar не проверены (нет свободного RAR-энкодера, D24).
- **`lsar` вызывается литералом**, не заведён `config('ai.tools.*')` (P3.6).
- **Формат `text_grid` зафиксирован докблоком `TextGridRenderer`** — переизобретать в P6 нельзя.
- **`unprocessable(conversion_failed/password_protected/archive_unsupported/ocr_failed)`
  терминален изнутри стратегии** — единообразие P3.4/P3.6/P3.7 сверено: все ставят
  `unprocessable:<reason>: <detail>` через `DocumentStoreRepository::update`.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR (теперь закрыт представлением, но качество —
  50 %), 21 файл КС-3 вне extraction, величина вне шести полей F1, OQ-3/OQ-4 открыты,
  `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1–P3.7 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим шагам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (не в item-коммитах P3.1–P3.7): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Ручные прогоны P3.4/P3.5/P3.7 создали реальные `Document`/`DocumentVersion` в DEV-БД (не
  `agelto_test`) через `tinker` — не убраны (P3.7: `external_id = p37-ocr-bench:*`); в контейнере
  остались файлы прогона `/tmp/ocr-bench/**` (в т.ч. 133-МБ `full.pdf`).
- `phases/P1.md` несёт `plan-lint`-ложноположительный WARN (буквальное `[NEEDS CLARIFICATION]` в
  тексте DoR-пункта) — P1 не трогался.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны, иначе падает на `MINIO_ROOT_PASSWORD`); `REDIS_PORT=6391`
  (`6379` занят `sova_redis`) — следующей сессии учитывать тот же env-оверрайд.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов; `REDIS_PORT=6391`; `make artisan`/`make ps`
  конфликтуют с файлом `artisan` в корне — звать `docker compose … exec app php artisan …`
  напрямую; запись в bind-mounted пути из контейнера требует ACL — `phpstan --generate-baseline`/
  `pint --dirty` пишутся с ХОСТА; git-диффы внутри контейнера требуют `git config --global --add
  safe.directory /var/www/html` под `www-data`.
- deferred: тест-двойники цепочки стратегий живут в `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (унаследовано от P3.1); отдельного `WordStrategyTest.php` нет; RAR-кейсы
  со сборкой архива — до появления свободного энкодера; `--image-dpi` для изображений без DPI —
  до отдельного D#; `@property`-аннотации моделям `Document`/`DocumentVersion` — до отдельного item'а.
- open_questions: OQ-3, OQ-4 — без изменений.
