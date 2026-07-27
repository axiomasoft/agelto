# HANDOFF — 2026-07-27 — after P3.1

**Next:** P3.1 закрыт 🟠 — следующий item фазы P3 — **P3.2** (системные бинари в единый образ +
`ai:tools:doctor`), solo, `Exec = plan-exec`, инфраструктурная сессия целиком (пересборка образа и
рестарт контейнеров).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — прописанный слой `apt-get` против D22–D24, открытых решений нет |
| Context | continue (/clear) — ручной item |
| Суть | Поставить системные бинари (qpdf, LibreOffice, 7zz, unar, poppler, tesseract, ocrmypdf, ghostscript) в `docker/php/Dockerfile` и завести доктор версий |

```
/task:plan-exec 2026.07.26-EST-TRAIN P3.2
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1 — 🟠 (item-коммит `9ea6147`):
таблица `prc_document_representations` + enum `RepresentationKind` + `RepresentationStoreRepository`
+ `RepresentationResolver` (`DEFAULT_PRIORITY = [Pdf]`, дефолтная политика = поведение до P3.1);
колонка `prc_document_versions.prepared_storage_path` удалена отдельной миграцией;
`PreparationStrategy::process()` возвращает `bool`, `DocumentPreparer` стал цепочкой (исчерпание →
`unprocessable(conversion_failed)`), `UnprocessableReason` дополнен тремя кейсами (D27);
`SpreadsheetStrategy` пишет представление `pdf` и на превышении порогов `ai.prepare.xlsx_*`
возвращает `false` вместо `too_large` (D26); три читателя (`ScreeningProcessor`,
`ExtractionOrchestrator`, `RerunRangeAction`) переведены на резолвер. Прогоны на `9ea6147`:
`migrate` применён, `RepresentationContract` 11 passed, `DocumentPreparer` 14 passed, `Screening`
34 passed, `Extraction` 43 passed, полный сюит 515 passed (1649 assertions), `phpstan` — `[OK] No
errors`, `pint --test` — PASS.

**Remaining:** фаза P3 — 6 items (P3.2 solo `plan-exec`; P3.3 manual implementation/high; B2 =
P3.4–P3.6 серией `plan-exec`; P3.7 manual implementation/high с вердиктом владельцу по потолку
OCR). Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.16.0, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5 (`grep prepared_storage_path … | wc -l` → `0`) недостижим по
  букве:** миграция удаления обязана называть колонку, историческая миграция создания
  неприкосновенна. Фактический счёт — `4` (на `9ea6147`), все в `database/migrations/**`; вне
  миграций — `0`. Ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1` — уточнить критерий.
  Тот же дефект формулировки будет у любого будущего item'а, удаляющего колонку.
- **`phpstan-baseline.neon` правился вне `Files` P3.1** (перегенерирован, 290 → 276 записей):
  файл не мог попасть в `Files`, потому что на момент дизайна плана phpstan в проекте не был
  подключён. Items P3.2–P3.7 и далее наследуют уже перегенерированный baseline.
- `too_large` как причина `unprocessable` на стадии подготовки более недостижим (D26): его
  единственным производителем был `SpreadsheetStrategy`. Кейс enum сохранён (data-affecting) —
  если P3.4/P3.7 захотят терминальный «слишком большой», причина должна выбираться сознательно.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Риск P2.1–P2.4 («write-site — placeholder») на P3.1 не
  распространяется; следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (в коммиты P3.1 не входит): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- `phases/P1.md` несёт `plan-lint`-ложноположительный WARN (буквальное `[NEEDS CLARIFICATION]` в
  тексте DoR-пункта) — P1 не трогался.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов (`--env-file .env --env-file .env.docker`),
  иначе падает на `MINIO_ROOT_PASSWORD`; `REDIS_PORT=6391` (6379 занят `sova_redis`); `make
  artisan`/`make ps` конфликтуют с файлом `artisan` в корне — звать `docker compose … exec app php
  artisan …` напрямую; запись в bind-mounted пути из контейнера требует ACL
  (`setfacl -R -m u:www-data:rwx <путь>`) — поэтому `phpstan --generate-baseline` писался во
  временный файл в контейнере и копировался на хост; `.env*` недоступны Read/Edit-тулам
  (harness-level deny).
- deferred: тест-двойники цепочки стратегий живут в самом `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (дифф вне `Files`); при появлении второго потребителя — вынести.
- open_questions: OQ-3, OQ-4 — без изменений.
