# HANDOFF — 2026-07-27 — after P3.3

**Next:** P3.3 закрыт 🟠 — следующий шаг фазы P3 — батч **B2 = P3.4–P3.6** (LibreOffice-конвертер,
`text_grid`, архивы 7z/RAR), все три `Exec = plan-exec`, `implementation/medium`, серия по
`roadmap.md` (`exec-items: task:plan-exec 2026.07.26-EST-TRAIN P3.4 P3.5 P3.6`).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — три item'а поверх зафиксированных контрактов (P3.1/P3.2), открытых решений нет |
| Context | continue (/clear) |
| Суть | Конвертеры фабрики представлений: `doc/docx`→PDF и fidelity `xls(x)`→PDF (LibreOffice), компактная текстовая сетка таблиц, 7z/RAR в `ArchiveStrategy` |

```
/task:plan-exec 2026.07.26-EST-TRAIN P3.4 P3.5 P3.6
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3.1 — 🟠 (item-коммит `9ea6147`):
контракт представлений (таблица + enum + резолвер + цепочка стратегий). P3.2 — 🟠 (item-коммит
`54b6d02`): девять системных бинарей в едином образе `docker/php/Dockerfile`. P3.3 — 🟠
(item-коммит `5a1d90f`): `GsfxStrategy`+`GsfxReader` (`app/Services/Procurement/Prepare/Gsfx/`) +
DTO-дерево `GsfxDocument` (`app/Dto/Procurement/Ai/Gsfx/`) — `.gsfx` → представления
`structured_xml`/`text`, вложения → дочерние документы; на реальном эталоне `EST-GSFX-LSR-001`
(`estimates/files/Администрация культуры и спорта 3.gsfx`, 6.2 МБ) `meta.positions` = `110`,
независимая сверка grep'ом Data.xml тоже `110` — сходится; 4 дочерних документа из
`Attachments/**`. Тесты — 11 passed (45 assertions), phpstan/pint зелёные. Два отклонения:
`phpstan-baseline.neon` правился вне `Files` (тот же прецедент, что P3.1/P3.2 — модели
`Document`/`DocumentVersion` без `@property`), и разведка `corpus-recon-2026-07-26.md` §3
разошлась с реальным `Data.xml` (акт `ImplemActs` поверх сметы, глобальные каталоги
`Indexes`/`Koefficients` вместо позиционных списков, `Item` — каталог подписантов, а не строка
позиции) — расхождение НЕ поменяло состав DTO (весь избыток честно виден в `meta.unmapped[]`),
эскалации не потребовалось — детали см. `phases/P3.md` P3.3 Completion Notes/Known Deviations.

**Remaining:** фаза P3 — 4 items (B2 = P3.4–P3.6, серия `plan-exec`; P3.7 manual
implementation/high, вердикт владельцу по потолку OCR). Фазы P4–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.16.0, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (ТЗ P3.1–P3.7, Completion Notes P3.1–P3.3) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/03-document-pipeline.md` §3.3 и `docs/specs/v2/01-database.md` (обновлены P3.1) ·
`estimates/manifest.json` (24 записи) · `docs/specs/v2/08-estimate-extraction.md` §8.

**Open risks:**
- **Acceptance P3.1 Validation-5 (`grep prepared_storage_path … | wc -l` → `0`) недостижим по
  букве:** миграция удаления обязана называть колонку, историческая миграция создания
  неприкосновенна. Фактический счёт — `4` (на `9ea6147`), все в `database/migrations/**`; вне
  миграций — `0`. Ремедиация: `/task:plan-design 2026.07.26-EST-TRAIN P3.1` — уточнить критерий.
- **P3.2 Validation-3 в буквальной форме ТЗ (`7zz i | head -3`) недостижима** — фактический бинарь
  `7z`; следующим items (P3.6 `ArchiveStrategy`) ориентироваться на реальный дефолт `7z`.
- **`GsfxPosition::$coefficients` для `EST-GSFX-LSR-001` всегда `[]`** (P3.3): глобальный каталог
  `Koefficients` в `Data.xml` не несёт явной ссылки позиция↔коэффициент (`Options="InPos …"` — флаг
  применимости, не ID) — если P5.1 обнаружит, что связь нужна, требуется отдельное решение по
  разбору `Options`/`VrGroupsLinks` (додумывать в P3.3 запрещено, не сделано).
- **`.gsfx`-контейнер несёт слой акта выполненных работ (`ImplemActs`), не только смету** (P3.3) —
  P3.3 его сознательно не моделирует (Scope Excluded); если корпус когда-то потребует разбирать
  акты из `.gsfx` отдельно от `EST-XLS-KS2-001`/`EST-XLS-KS3-001` — отдельное решение, не найдено
  need пока.
- **`phpstan-baseline.neon` правился вне `Files` уже ТРИ раза подряд** (P3.1, P3.2, P3.3) — тот же
  структурный пробел (`Document`/`DocumentVersion` без `@property`-докблоков в моделях); дальнейшие
  items P3 унаследуют уже актуальный baseline. Стоит рассмотреть отдельный item/D# на добавление
  `@property`-докблоков моделям, чтобы снять повторяющееся отклонение — не решено здесь.
  `too_large` как причина `unprocessable` на стадии подготовки более недостижим (D26): его
  единственным производителем был `SpreadsheetStrategy`. Кейс enum сохранён (data-affecting).
- Все риски, унаследованные от P1/P2: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне
  шести полей F1, OQ-3/OQ-4 открыты, `gold_path` эталона `EST-PDF-SCAN-001` остаётся `null`
  (D91), `phpstan`/`larastan` подключены решением владельца вне плана (коммит `7485520`).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P3.1/P3.2/P3.3 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow`. Следующим item'ам использовать тот же PYTHONPATH.
- Посторонний дифф в рабочем дереве (не в item-коммиты P3.1/P3.2/P3.3): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- `phases/P1.md` несёт `plan-lint`-ложноположительный WARN (буквальное `[NEEDS CLARIFICATION]` в
  тексте DoR-пункта) — P1 не трогался.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны, иначе падает на `MINIO_ROOT_PASSWORD`); `REDIS_PORT=6391`
  (`6379` занят `sova_redis`) — следующей сессии учитывать тот же env-оверрайд.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов (`--env-file .env --env-file .env.docker`);
  `REDIS_PORT=6391`; `make artisan`/`make ps` конфликтуют с файлом `artisan` в корне — звать
  `docker compose … exec app php artisan …` напрямую; запись в bind-mounted пути из контейнера
  требует ACL — `phpstan --generate-baseline`/`pint --dirty` пишутся с ХОСТА (доступен напрямую,
  `./vendor/bin/{phpstan,pint}` работают без контейнера) либо во временный файл в контейнере с
  копированием на хост; `.env*` недоступны Read/Edit-тулам (harness-level deny); git-диффы внутри
  контейнера (`pint --dirty` из контейнера) требуют `git config --global --add safe.directory
  /var/www/html` под `www-data` (не персистентно между пересборками).
- deferred: тест-двойники цепочки стратегий живут в самом `RepresentationContractTest.php`, а не в
  `tests/Support/Doubles/` (дифф вне `Files`); при появлении второго потребителя — вынести.
- open_questions: OQ-3, OQ-4 — без изменений.
