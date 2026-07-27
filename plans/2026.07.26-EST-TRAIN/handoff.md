# HANDOFF — 2026-07-27 — after P3.5

**Next:** P3.5 закрыт 🟠 — следующий шаг фазы P3 — последний item батча **B2 = P3.6**
(архивы 7z/RAR в `ArchiveStrategy`), `Exec = plan-exec`, `implementation/medium`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — item поверх зафиксированных контрактов (P3.1/P3.2), открытых решений нет |
| Context | continue (/clear) |
| Суть | `ArchiveExtractor` — 7z (`7zz`/`7z`) и RAR (`unar`) поверх существующего ZIP-пути `ArchiveStrategy` |

```
/task:plan-exec 2026.07.26-EST-TRAIN P3.6
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1 — 🟠 (item-коммит `9ea6147`):
контракт представлений. P3.2 — 🟠 (item-коммит `54b6d02`): системные бинари в едином образе.
P3.3 — 🟠 (item-коммит `5a1d90f`): `GsfxStrategy`+`GsfxReader`. P3.4 — 🟠 (item-коммит
`3c0755a`): `LibreOfficeConverter`+`WordStrategy`+LibreOffice-фолбэк `SpreadsheetStrategy`,
реальные эталоны дали кириллицу без квадратов. P3.5 — 🟠 (item-коммит `d1ac74d`):
`TextGridRenderer` — второе представление `text_grid` рядом с `pdf` (row_index-якорь, merge
развёрнут, пустые строки/колонки опущены без сдвига якоря); реальный эталон
`EST-XLSX-KS2-001` дал оба представления (`pdf` 96608Б/8 стр., `text_grid` 86756Б,
`token_estimate=21689`) — сетка чуть меньше PDF, вывод по выбору формата — предмет P6.
Отклонение: `setReadDataOnly(true)` снят — PhpSpreadsheet Xlsx-ридер при этом флаге пропускает
`<mergeCells>`, что несовместимо с обязательным разворачиванием merge; size-gate не ослаблен
(считается отдельно, `filesize()`+`listWorksheetInfo()` до полной загрузки).

**Remaining:** фаза P3 — 2 items (P3.6 — хвост B2; P3.7 manual implementation/high, вердикт
владельцу по потолку OCR). Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1–P3.5) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5** (`grep prepared_storage_path … | wc -l` → `0`) недостижим по
  букве — фактический счёт `4`, все в `database/migrations/**`; ремедиация:
  `/task:plan-design 2026.07.26-EST-TRAIN P3.1`.
- **P3.2 Validation-3** в буквальной форме (`7zz i | head -3`) недостижима — фактический бинарь
  `7z`; P3.6 ориентируется на реальный дефолт, `config('ai.tools.sevenzip_bin')` уже отражает факт.
- **`GsfxPosition::$coefficients`** для `EST-GSFX-LSR-001` всегда `[]` (P3.3) — без изменений.
- **`.gsfx`-контейнер несёт слой `ImplemActs`**, не только смету (P3.3) — сознательно не
  моделируется, без изменений.
- **`phpstan-baseline.neon` правился вне `Files` уже ПЯТЬ раз подряд** (P3.1–P3.5) — тот же
  структурный пробел (`Document`/`DocumentVersion` без `@property`). Рабочий приём для
  регенерации (P3.4): временный `phpstan.neon` без `includes: phpstan-baseline.neon` +
  абсолютные пути + `--generate-baseline=/tmp/…` + построчная сверка `message:` + `cp` на хост —
  P3.6 (если понадобится) использует тот же приём.
- **`unprocessable(conversion_failed)` теперь терминален изнутри стратегии** (P3.4/P3.5) —
  если P3.6/P3.7 тоже переводят исчерпание собственных веток в терминальный исход, сверить
  единообразие `last_error`.
- **Формат `text_grid` зафиксирован докблоком `TextGridRenderer`** — переизобретать в P6 нельзя
  (P6 сравнивает представления, а не варианты одного). `setReadDataOnly` сознательно НЕ
  используется (см. Known Deviations P3.5) — при рефакторинге `TextGridRenderer` не возвращать
  флаг обратно не проверив merge-тест.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1–P3.5 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (не в item-коммиты P3.1–P3.5): `.gitignore` (M),
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
  `WordStrategyTest.php` нет — кейсы `WordStrategy` живут в `SpreadsheetStrategyTest.php` (так
  задан `Files` item'а P3.4).
- open_questions: OQ-3, OQ-4 — без изменений.
