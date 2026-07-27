# HANDOFF — 2026-07-27 — after P3.2

**Next:** P3.2 закрыт 🟠 — следующий item фазы P3 — **P3.3** (`.gsfx`: распаковка + `Data.xml` →
структурная модель и текст), `Exec = manual`, `implementation/high`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — cp1251, XXE, отображение незнакомых элементов в `unmapped`; якорь детерминированного gold (D2) |
| Context | continue (/clear) — ручной item |
| Суть | `GsfxStrategy` + `GsfxReader`: распаковка ZIP-контейнера ГрандСметы, парсинг `Data.xml` (cp1251, XXE-защита) в структурную модель и текстовое представление |

```
/model sonnet
/effort high
/task:plan-run 2026.07.26-EST-TRAIN P3.3
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1 — 🟠 (item-коммит `9ea6147`):
контракт представлений (таблица + enum + резолвер + цепочка стратегий). P3.2 — 🟠 (item-коммит
`54b6d02`): девять системных бинарей в едином образе `docker/php/Dockerfile` (LibreOffice
calc+writer с кириллическими шрифтами, `7zip`, `unar`, `poppler-utils`, `tesseract-ocr`+`rus`/`eng`,
`ocrmypdf`, `ghostscript`) поверх `qpdf`; блок `config('ai.tools')` (8 путей бинарей +
`default_timeout_seconds`); `ai:tools:doctor {--json}` (`Process`-фасад, список-аргументы,
`Process::fake()` в тесте); `ToolsDoctorTest` 3 passed. Прирост образа: `753888071` →
`1359330595` байт (≈605 МБ, в пределах бюджета 1 ГБ). Отклонение: пакет `7zip` на фактической
базовой версии образа даёт бинарь `7z`, а не `7zz`, как предполагал RAG
(`findings/rag-P3-tooling-2026-07-26.md` §1) — конфиг-дефолт `sevenzip_bin` исправлен на `7z` по
факту доктора, D22–D24 не затронуты (пакет и лицензионная развилка те же). `pint --dirty --test` —
PASS (4 files, после разовой `git config --global --add safe.directory /var/www/html` внутри
контейнера — bind-mount иначе не даёт git-диффу работать под `www-data`).

**Remaining:** фаза P3 — 5 items (P3.3 manual implementation/high; B2 = P3.4–P3.6 серией
`plan-exec`; P3.7 manual implementation/high с вердиктом владельцу по потолку OCR). Фазы P4–P9 не
начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.16.0, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1–P3.2) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5 (`grep prepared_storage_path … | wc -l` → `0`) недостижим по
  букве:** миграция удаления обязана называть колонку, историческая миграция создания
  неприкосновенна. Фактический счёт — `4` (на `9ea6147`), все в `database/migrations/**`; вне
  миграций — `0`. Ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1` — уточнить критерий.
  Тот же дефект формулировки будет у любого будущего item'а, удаляющего колонку.
- **P3.2 Validation-3 в буквальной форме ТЗ (`7zz i | head -3`) недостижима** — фактический бинарь
  `7z` (см. Done); последующим items (P3.6 `ArchiveStrategy`), обращающимся к `config('ai.tools.
  sevenzip_bin')`, ориентироваться на реальный дефолт `7z`, не на текст RAG-находки.
- **`phpstan-baseline.neon` правился вне `Files` P3.1** (перегенерирован, 290 → 276 записей):
  файл не мог попасть в `Files`, потому что на момент дизайна плана phpstan в проекте не был
  подключён. Items P3.3–P3.7 и далее наследуют уже перегенерированный baseline.
- `too_large` как причина `unprocessable` на стадии подготовки более недостижим (D26): его
  единственным производителем был `SpreadsheetStrategy`. Кейс enum сохранён (data-affecting) —
  если P3.4/P3.7 захотят терминальный «слишком большой», причина должна выбираться сознательно.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1/P3.2 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (в коммиты P3.1/P3.2 не входит): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- `phases/P1.md` несёт `plan-lint`-ложноположительный WARN (буквальное `[NEEDS CLARIFICATION]` в
  тексте DoR-пункта) — P1 не трогался.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) пересобраны и подняты на `docker-compose.yml`
  с `REDIS_PORT=6391` (воркараунд, `6379` занят `sova_redis`) — следующей ручной сессии учитывать
  тот же env-оверрайд при `docker compose up`.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов (`--env-file .env --env-file .env.docker`),
  иначе падает на `MINIO_ROOT_PASSWORD`; `REDIS_PORT=6391` (6379 занят `sova_redis`); `make
  artisan`/`make ps` конфликтуют с файлом `artisan` в корне — звать `docker compose … exec app php
  artisan …` напрямую; запись в bind-mounted пути из контейнера требует ACL
  (`setfacl -R -m u:www-data:rwx <путь>`) — поэтому `phpstan --generate-baseline` писался во
  временный файл в контейнере и копировался на хост; `.env*` недоступны Read/Edit-тулам
  (harness-level deny); git-диффы внутри контейнера (`pint --dirty`) требуют разовый `git config
  --global --add safe.directory /var/www/html` под `www-data` (bind-mount, не персистентно между
  пересборками контейнера).
- deferred: тест-двойники цепочки стратегий живут в самом `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (дифф вне `Files`); при появлении второго потребителя — вынести.
- open_questions: OQ-3, OQ-4 — без изменений.
