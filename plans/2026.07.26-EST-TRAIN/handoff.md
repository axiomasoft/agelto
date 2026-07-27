# HANDOFF — 2026-07-27 — after P3.4

**Next:** P3.4 закрыт 🟠 — следующий шаг фазы P3 — оставшиеся два item'а батча **B2 = P3.5–P3.6**
(компактная текстовая сетка `text_grid`, архивы 7z/RAR), оба `Exec = plan-exec`,
`implementation/medium`, серия по `roadmap.md` (`exec-items: task:plan-exec 2026.07.26-EST-TRAIN
P3.5 P3.6`).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — два item'а поверх зафиксированных контрактов (P3.1/P3.2/P3.4), открытых решений нет |
| Context | continue (/clear) |
| Суть | Конвертеры фабрики представлений: компактная текстовая сетка таблиц `text_grid` (вторая ветка `SpreadsheetStrategy`), 7z/RAR в `ArchiveStrategy` |

```
/task:plan-exec 2026.07.26-EST-TRAIN P3.5 P3.6
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1 — 🟠 (item-коммит `9ea6147`):
контракт представлений (таблица + enum + резолвер + цепочка стратегий). P3.2 — 🟠 (item-коммит
`54b6d02`): девять системных бинарей в едином образе `docker/php/Dockerfile`. P3.3 — 🟠
(item-коммит `5a1d90f`): `GsfxStrategy`+`GsfxReader` — `.gsfx` → `structured_xml`/`text`,
110/110 позиций сошлось с независимым grep на реальном эталоне. P3.4 — 🟠 (item-коммит
`3c0755a`): `LibreOfficeConverter` (изолированный профиль на вызов) + `WordStrategy`
(`doc/docx/rtf/odt`→PDF, единственная стратегия для этих расширений — до этого item'а такие файлы
уходили в LLM оригиналом через `PassthroughStrategy`) + вторая (LibreOffice) ветка
`SpreadsheetStrategy` (xlsx сверх `xlsx_max_mb`/`xlsx_max_rows` либо упавший PhpSpreadsheet теперь
пробует LibreOffice, обе ветки упали → `unprocessable(conversion_failed)` — раньше превышение
лимита молча уходило в `PassthroughStrategy` без представления). Реальные эталоны
`NEG-DOCX-CONTRACT-001`/`EST-XLSX-VOR-001` дали представления с кириллицей без квадратов
(`pdftotext`). Тесты — `LibreOfficeConverter` 7 passed, `SpreadsheetStrategy` (несёт и
`WordStrategy`-кейсы, отдельного `WordStrategyTest.php` Files item'а не заводит) 9 passed,
`DocumentPreparer`-регресс 14 passed; phpstan/pint зелёные. Отклонение: `DocumentPreparerTest.php`
переписан (3 теста) под намеренную смену поведения D26/P3.4 (лимит инструмента больше не означает
молчаливый fallthrough для xlsx) + baseline вне `Files` (3 новые уникальные ошибки от
`WordStrategy.php`, ни одна из 290 прежних не потеряна — детали см. `phases/P3.md` P3.4 Completion
Notes/Known Deviations).

**Remaining:** фаза P3 — 3 items (P3.5–P3.6 — оставшийся хвост B2, серия `plan-exec`; P3.7 manual
implementation/high, вердикт владельцу по потолку OCR). Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1–P3.4) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5 (`grep prepared_storage_path … | wc -l` → `0`) недостижим по
  букве:** фактический счёт — `4` (все в `database/migrations/**`); вне миграций — `0`.
  Ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1` — уточнить критерий.
- **P3.2 Validation-3 в буквальной форме ТЗ (`7zz i | head -3`) недостижима** — фактический бинарь
  `7z`; P3.6 (`ArchiveStrategy`) ориентируется на реальный дефолт `7z`, `config('ai.tools.sevenzip_bin')`
  уже отражает факт.
- **`GsfxPosition::$coefficients` для `EST-GSFX-LSR-001` всегда `[]`** (P3.3) — см. предыдущий handoff,
  без изменений.
- **`.gsfx`-контейнер несёт слой акта выполненных работ (`ImplemActs`), не только смету** (P3.3) —
  без изменений, P3.3 сознательно не моделирует.
- **`phpstan-baseline.neon` правился вне `Files` уже ЧЕТЫРЕ раза подряд** (P3.1–P3.4) — тот же
  структурный пробел (`Document`/`DocumentVersion` без `@property`-докблоков); P3.4 подтвердил, что
  регенерация безопасна (построчная сверка `message:` перед копированием — 290 старых сохранились,
  добавились только 3 новые), но сам пробел не закрыт. Стоит рассмотреть отдельный item/D# на
  добавление `@property`-докблоков моделям — не решено здесь.
- **Регенерация baseline из контейнера не может писать напрямую в bind-mounted `phpstan-baseline.neon`**
  (ACL, как и раньше) — P3.4 подтвердил рабочий обходной путь: временный `phpstan.neon` без
  `includes: phpstan-baseline.neon` (иначе `--generate-baseline` в другой файл видит только НОВЫЕ
  ошибки поверх уже подавленных, а не полный список) + абсолютные пути в `includes`/`paths`
  (относительные резолвятся от `-c`, а не от cwd) → `--generate-baseline=/tmp/…` → сверка `message:`
  построчно → `cp` на хост. Следующим items (P3.5–P3.7), если понадобится baseline, — тот же приём.
- **`unprocessable(conversion_failed)` теперь возможен для xlsx/doc/docx при падении ОБЕИХ веток**
  конвертации (P3.4, было — только при исчерпании всей цепочки P3.1) — если P3.5/P3.6/P3.7 тоже
  переводят исчерпание собственных веток в терминальный исход внутри стратегии (а не `false`),
  сверить с этим прецедентом единообразие сообщения `last_error`.
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1–P3.4 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (не в item-коммиты P3.1–P3.4): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Ручной прогон P3.4 (Validation-3) создал реальные `Document`/`DocumentVersion` в DEV-БД
  (не `agelto_test`) через `tinker` — не убраны (тот же класс побочного артефакта, что ручные
  прогоны P3.1–P3.3); не блокирует, но стоит учитывать при разборе dev-данных.
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
  `cp` на хост (см. Open risks выше); git-диффы внутри контейнера требуют `git config --global
  --add safe.directory /var/www/html` под `www-data` (не персистентно между пересборками).
- deferred: тест-двойники цепочки стратегий живут в самом `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (дифф вне `Files`, унаследовано от P3.1); при появлении второго
  потребителя — вынести. Отдельного `WordStrategyTest.php` нет — кейсы `WordStrategy` живут в
  `SpreadsheetStrategyTest.php` (так задан `Files` item'а P3.4).
- open_questions: OQ-3, OQ-4 — без изменений.
