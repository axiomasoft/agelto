# HANDOFF — 2026-07-27 — after P4.4

**Next:** P4.4 закрыт 🟠 — фаза P4 идёт 3/5 (🟢) + 1 (🟠), остался **P4.5** — последний item фазы.
Solo, `Exec = manual`, `implementation/high` — запускать по D18 через `plan-run` под sonnet/high,
как и P4.4.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — cross-layer инвариант, синхронизация DTO/спеки под утверждённую схему |
| Context | continue (/clear) — ручной item |
| Суть | Контракты ответа LLM + §1/§3/§4а/§8.2 спеки + карта следствий `research/08-schema-change-impact.md` |

```
/model sonnet
/effort high
/task:plan-run 2026.07.26-EST-TRAIN P4.5
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3 — терминальна (0/7 🟢, 7/7 🟠).
P4.1 — 🟢 (`a72fa22` + фикс `6c74e92`): `research/05-schema-audit.md`, двусторонняя матрица схемы.
P4.2 — 🟢 (`381afa4`): `research/06-schema-decision.md` — критерий `N = 9`, распределение всех
величин по носителям, +25 колонок, D92–D105, апрув владельца «Утверждаю целиком».
P4.3 — 🟢 (`55b8f6d`): `research/07-multi-estimate-anchoring.md`, адрес сметы — 5 колонок
`source_*`, `EstimateSourceKind`, D106–D109.

**P4.4 — 🟠 Done with deviations** (item-коммит `c1b0c89`): миграции/модели/фабрики
`prc_estimate*` по утверждённому списку §8+§11 — 30 новых колонок (`calc_method`,
`labor_hours_workers`/`labor_hours_machinists`, `document_number`/`document_date`/
`period_start`/`period_end`, `verified_at`/`verified_by`, `source_sheet_name`/`source_row_start`/
`source_row_end`/`source_page_start`/`source_page_end`, `unit_price_base`/`total_price_base`,
`index_code`/`index_value` ×2, `unit_base`/`unit_multiplier` ×2, `source_position_number`,
`work_type_id`, `price_base`, `chapter_number`, `rate`) · 3 переименования (`unit_price`→
`unit_price_curr`, `total_price`→`total_price_curr`, `price`→`price_curr`) · 3 смены типа
(`procurement_id`→nullable, `prc_estimate_totals.amount`→nullable, `.kind`→enum `TotalKind`) ·
2 удаления (`prc_estimates.title`, `prc_estimate_sections.section_number`) · новая таблица
`prc_estimate_work_types` (13 колонок, D34/D90) · новые enum'ы `CalcMethod`/`TotalKind`/
`EstimateSourceKind` · аксессоры `Estimate::title()`/`sourceKind()` (D100/D106) · два названных
легальных правки `EstimatePersister.php` (снятие guard `procurement_id===null` — D98; перенос
сборки `title` на аксессор — D100) · `EstimateSchemaTest.php` (9 тестов) · `migrate:fresh --seed`
+ восстановление реестра эталонов (24/24) — все зелёные · `phpstan`/`pint` зелёные ·
`docs/03_Dev/database.md` синхронизирован.

**Эскалация внутри той же сессии (снята владельцем).** Гейт непустоты ДО `fresh` нашёл три
непустые dev-таблицы (`prc_procurements`/`prc_documents`/`prc_document_versions` — синтетический
Faker-мусор ручных прогонов P3.4/P3.5/P3.7). Владелец решил в чате: «Очистить эти 3 таблицы» —
очищены (вместе с зависимыми `prc_events`, найденными по FK-констрейнту), гейт пересчитан зелёным,
исполнение продолжено тем же прогоном. Полный разбор — `phases/P4.md` P4.4 (blockquote + резолюция
+ Completion Notes).

**Known Deviations P4.4** (полный текст — `phases/P4.md`): (1) гейт непустоты прогнан после начала
правки кода, а не до (process, БД не пострадала); (2) три непустые таблицы гейта — эскалация,
снята владельцем в сессии; (3) `Files` item'а не перечислял три enum-файла — добавлены в коммит
как естественное следствие Deliverables; (4) `phpstan-baseline.neon` правлен вне `Files` (4 новые
`property.notFound` на аксессорах `Estimate`) — тот же прецедент, что и раньше в этом плане.

**Найдено эмпирически, НЕ являлось Scope P4.4 — обязательный вход в карту следствий P4.5**
(`research/08-schema-change-impact.md`): `EstimateToContractMapper.php` (Scope Excluded P4.4)
ломается ДВУМЯ независимыми причинами после переименований/каста P4.4 — (а) `:122-123,131` читают
`$item->unit_price`/`$item->total_price`/`$resource->price` (колонок больше нет, читаются как
`null`); (б) `:78` передаёт `$total->kind` (теперь `TotalKind`) в `TotalLine::$kind` (типизирован
`?string`, `app/Dto/Procurement/Ai/Estimate/TotalLine.php:19`) — `TypeError`, подтверждено в
tinker. Тест `EstimateToContractMapperTest.php` красный (сам пишет литеральные `unit_price`/
`total_price` в фабрику, а Laravel-фабрики обходят `$fillable` через `Model::unguarded()` — SQL
падает на несуществующей колонке). `EstimatePersisterTest.php`, наоборот, зелёный: путь через
`Model::updateOrCreate()` уважает `$fillable` и молча роняет неизвестные ключи (без SQL-ошибки,
просто `*_curr` не заполняются до P4.5). P4.5 должен переписать литералы на `*_curr` в
`EstimateToContractMapper.php`+тесте и типизировать `TotalLine::$kind` под `?TotalKind` (тот же
паттерн, что `RowResource::$resource_type: ResourceType`).

**Remaining:** P4.5 (контракты ответа LLM, спека §1/§3/§4а/§8.2, карта следствий — включая находки
выше). Фазы P5–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D109) ·
`plans/2026.07.26-EST-TRAIN/phases/P4.md` (ТЗ P4.1–P4.5 + Completion Notes P4.1–P4.4) ·
**`plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §8 + §11 (утверждённый список —
единственный легальный вход P4.4/P4.5)** · `plans/2026.07.26-EST-TRAIN/research/07-multi-estimate-anchoring.md`
(решения по многосметности, фактура кода с `файл:строка`) ·
`plans/2026.07.26-EST-TRAIN/research/05-schema-audit.md` (матрица, обратная сверка, конфликты) ·
`plans/2026.07.26-EST-TRAIN/research/03-schema-requirements.md` (`SR-01…SR-17`) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/08-estimate-extraction.md` §1/§3/§4а/§8.2 · миграции `2026_07_04_1000{33..37}` +
`2026_07_27_100057` (новая, `prc_estimate_work_types`) · `estimates/manifest.json` (24 записи).

**Open risks:**
- **P4.5 обязана переписать литералы старых имён колонок в `EstimateToContractMapper.php` и
  типизировать `TotalLine::$kind`** — см. раздел выше, оба места сломаны ПОСЛЕ P4.4, до P4.5
  чинить их не Scope. `EstimatePersister`/`RerunRangeAction` пишут теми же старыми литералами
  (`unit_price`/`total_price`/`price`) — не падают (мимо `$fillable` тихо), но и не заполняют
  новые колонки; тот же список правится в P4.5.
- **P4.5 несёт пять следствий, которые обязаны попасть в карту следствий**, иначе они всплывут в
  чужой фазе: (1) поле F1 `price` переадресуется на `total_price_curr` — до правки `EvalMetrics`
  (P7.3) метрика считает по несуществующему полю; (2) словарь нормализации единиц (D104) — новый
  код с собственным владельцем, до него `unit_base`/`unit_multiplier` пусты; (3) `summary_row`
  требует правки промта P7.2 и словаря §3.5; (4) правило «какие поля обязательны при каком
  `calc_method`» — код валидатора, план его не пишет; (5) правило сопоставления смет в eval
  (`estimate_number` → адрес → позиция, D107) — правка `EvalMetrics`, владелец P7.3.
- **Листовая ветка адреса (D106) без писателя**: `source_sheet_name`/`source_row_*` заполнятся
  только если P6.3 выберет входом `text_grid` либо из мапера книг P5.2. Сегодня извлечение
  принимает только PDF/изображения (`ExtractionOrchestrator.php:739-743`), книга доезжает
  производным PDF, имя листа теряется. Риск «пустая колонка» принят владельцем сознательно.
- **Схема §4.2 (`modifiers[]`) по gsfx-ветке не заполняется без правки P3.3**: `GsfxPosition::$coefficients`
  пуст по построению; `knownTypes` не знает `Tzm2022`; `Quantity@KUnit` не переносится. Вход P5.1.
- **`prc_estimate_work_types` полноценно заполняется только gsfx-веткой**: у 61 книги корпуса
  `overhead_rate`/`profit_rate`/`parent_id` останутся пустыми (осознанное исключение из D33).
- **`verified_at`/`verified_by` заводятся БЕЗ писателя внутри плана** (D89/D99) — риск «пустая
  колонка» принят владельцем сознательно; экран боевого просмотра и промоушен в датасет — долг вне
  плана.
- **Дефект `plan-lint`: ложное срабатывание на отрицании** (унаследовано из P1/P2, без изменений).
- Все риски, унаследованные от P1–P3, без изменений: acceptance P3.1 Validation-5 недостижим по
  букве; P3.2 Validation-3 в буквальной форме недостижима; `phpstan-baseline.neon` правился вне
  `Files` теперь ВОСЕМЬ раз подряд (кандидат на item `@property`-аннотаций); потолок OCR 50 % — на
  нём нельзя строить автоматический gold; `pdf_ocr` 132-МБ эталона весит 133 МБ и не влезает в
  лимит маршрута; ветка изображений OCR-ит только картинки с DPI в метаданных; `.gsfx` несёт слой
  `ImplemActs`, сознательно не моделируемый; RAR-путь `ArchiveStrategy` покрыт частично; `lsar`
  вызывается литералом; формат `text_grid` зафиксирован докблоком; 21 файл КС-3 вне extraction;
  OQ-3/OQ-4 открыты.
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P4.4 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow` (оба). Следующим шагам использовать тот же PYTHONPATH.
- **`git add plans/<PLAN-ID>` затягивает эфемерные логи тулинга.** В каталоге плана живут
  `.claude/` и `.swissknife/` (feature-log, `audit`/`events` ndjson, ephemeral-снимки сессий) —
  коммитить ПО ЯВНЫМ ПУТЯМ (`phases/P4.md`, `handoff.md`, `journal.jsonl`, `plan.md`), не
  `git add plans/<PLAN-ID>` целиком, пока в корневой `.gitignore` не добавлены
  `plans/**/.claude/`/`plans/**/.swissknife/` (правка владельца, файл правится им параллельно).
- **`plan.md` неделим между item-коммитом и бухгалтерией** — то же свойство топологии, что у
  P4.2/P4.3 (не ошибка исполнителя, `git add` по явному пути стажирует файл целиком).
- Посторонний дифф в рабочем дереве (унаследован от P3, не трогался P4.4): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Ручные прогоны P3.4/P3.5/P3.7 оставляли файлы `/tmp/ocr-bench/**` в контейнере — не убраны
  (вне Scope, не блокирует код).
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны, иначе падает на `MINIO_ROOT_PASSWORD`); `REDIS_PORT=6391`
  (`6379` занят `sova_redis`). Хостовый `phpstan`/`pint` — `DB_HOST=127.0.0.1 DB_PORT=<порт из
  `docker compose port postgres 5432`>` (сегодня `5455`) переопределением env, `.env`-файл не
  трогать.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов; `REDIS_PORT=6391`; `make artisan`/`make ps`
  конфликтуют с файлом `artisan` в корне — звать `docker compose … exec app php artisan …` напрямую;
  запись в bind-mounted пути из контейнера требует ACL (`phpstan --generate-baseline`/`pint --dirty`
  — с ХОСТА, `phpstan --generate-baseline` при этом пишет ХОСТ-пути и не мержится с существующим
  контейнер-путевым baseline — новые записи `property.notFound` добавляются вручную по формату
  соседних); git-диффы внутри контейнера требуют `git config --global --add safe.directory`;
  каталог `storage/app/private/estimates` не читается с хоста (Permission denied) — файл эталона
  берётся из `estimates/files/`; сигнатура `GsfxReader::parse($propertiesRaw, $dataXmlRaw)`, не
  `read($path)`; НЕ использовать `docker compose exec app php artisan tinker` для проверок,
  требующих создания моделей верхнего уровня (`Estimate`/`Procurement`/`Document`/…) — фабрики
  пишут в DEV-БД (`agelto`), не в `agelto_test`, и оставляют мусор, который следующий гейт
  непустоты снова найдёт; если нужно проверить поведение кода — `agelto_test` через Pest.
- deferred: тест-двойники цепочки стратегий живут в `RepresentationContractTest.php`; отдельного
  `WordStrategyTest.php` нет; RAR-кейсы со сборкой архива — до свободного энкодера; `--image-dpi`
  для изображений без DPI — до отдельного D#; `@property`-аннотации моделям `Document`/
  `DocumentVersion`/`Estimate` — до отдельного item'а; правка докблоков, `knownTypes` и связи
  `Koefficients` в `GsfxReader` — P5.1; детерминированное доопределение `prc_estimate_totals.kind`
  по `label` — долг (D101); общий справочник видов работ (`ref_work_types`) — долг D34/D90;
  писатель оси `verified_at` и промоушен боевой сметы в датасет — долг вне плана (D89 п. в);
  писатель адреса сметы и переиндексация строк оконного пути — долги D106/§4а, адрес фиксации —
  карта следствий P4.5; `EstimateToContractMapper.php` + `EstimateToContractMapperTest.php` +
  `TotalLine::$kind` — правка P4.5 (см. раздел выше).
- open_questions: OQ-3, OQ-4 — без изменений. OQ-5/OQ-6 закрыты D89/D90 и не переоткрываются.
