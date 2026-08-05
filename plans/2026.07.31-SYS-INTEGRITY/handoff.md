# HANDOFF — 2026-08-05 — after P4.6

**Next:** manual: frontier/high — item P4.7 (боевой прогон-смоук архивного профиля через прокси,
2–3 дня 2025 года, метрики нагрузки БД в трёх точках, калибровка констант дробления по фактам).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — предписано §3 Routing (`frontier/high`) |
| Context | continue (/clear) — ручной item |
| Суть | Прогнать `crawler:run-archive` на засеянном архивном профиле за окно 2–3 дня 2025 (`research/05` §6), снять метрики `t0`/`t1`/`t2` (§7), откалибровать `eis.block_size`/`archive.price_ceiling`/`min_price_interval`/`max_blocks_per_window` по фактам, зафиксировать `findings/P4-load-baseline.md` + `artifacts/p4-run-metrics.json` |

```text
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.7
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-05) — P4.6 закрыт 🟠, item-коммит
`d297d44`.**

- **Шесть позиций группы А `research/05` §3.1 закрыты.** Блок `tracking` в `config/crawler.php`
  (`window_overlap_days=2`, `delta_scan_enabled=false`, `delta_scan_cron='0 23 * * *'`) + снята
  метка «экстраполяция, D24» из докблока `archive` (ссылка на `findings/P4-eis-recon.md` §2).
  `BacklogRescanProcessor::drainWindow()` сдвигает старт окна назад на `window_overlap_days`,
  лог несёт фактические границы. Новый `DeltaScanProcessor` (переиспользует
  `ArchiveWindowScanner`, профиль резолвится тем же способом, что `BacklogRescanProcessor:62-67`)
  + команда `crawler:run-delta {crawler?}` + регистрация в `routes/console.php` под флагом
  (выключен). `CrawlerRunManager::recordScanGap()`/`recordOldestUpdateSeen()` пишут
  `scan_gap_seconds`/`oldest_update_seen` в `prc_crawler_runs.stats` (только fresh-прогон);
  `lastCompletedRun()` снял второй носитель запроса, ранее жившего только в
  `FreshRunProcessor::handleCapHit()`. `CsvBlockIngestor::ingest()` дедуплицирует строки блока по
  `registryNumber` (первая строка выигрывает) со счётчиком `FetchResult::$rowsDuplicated` +
  `Log::warning`. `HasPriceRange::startingPriceInterval()` (новый узкий интерфейс) +
  `EisSearchFilter`-реализация — `ArchiveWindowScanner` берёт стартовый ценовой стек из `priceTo`
  профиля, падает на `config('crawler.archive.price_ceiling')` без него; сканер остался
  площадко-агностиком (`instanceof HasPriceRange`, не `instanceof EisSearchFilter`).
- **Регрессионный протокол П-2 процитирован:** `git stash` диффа `BacklogRescanProcessor.php` →
  `--filter=ChangeTrackingWindow` → `Failed asserting that 3 is identical to 5.` (без перекрытия
  видно только 3 дня из 5) → `git stash pop` → тот же фильтр зелёный (3 assertions).
- **Восемь новых тестов** (`ChangeTrackingWindowTest`, `DeltaScanTest` ×2, +1 в
  `CsvBlockIngestorTest` — 510/500 дедуп, +2 в `FreshRunProcessorTest` — метрики отставания, +1 в
  `ArchiveWindowScannerTest` — приоритет `priceTo`, +1 в `BacklogRescanProcessorTest` — сдвиг
  окна). Полный сюит на item-коммите (`d297d44`) — **10 failed / 1048 passed**: тот же список имён,
  что документированная база (`pg_partman` ×4, `CoreKnowsNoDocumentTypeTest` ×2,
  `PromptApplyTest` ×2, `CostReportTest` ×1, `TextGridRendererTest` ×1), прирост `passed` — только
  восемь новых тестов item'а (K=0).
- **Material-отклонение (🟠), полностью названо:** item-коммит несёт дифф вне `Files` —
  `@property`-докблок `app/Models/Procurement/Crawler/Run.php` (легальный рычаг: без него PHPStan
  падает на новых `$run->status`/`$run->started_at`/`$run->finished_at` в
  `CrawlerRunManager`/`RunDeltaScan` — у `Run`, в отличие от `Crawler`, докблока не было вовсе),
  сокращение `phpstan-baseline.neon` на 4 записи (стали `ignore.unmatched` после докблока —
  СОКРАЩЕНИЕ, не рост), и `tests/Feature/Console/RescanBacklogTest.php` (`window_overlap_days=0` в
  `beforeEach` — тест проверяет делегирование команды однодневным блоком, реальный дефолт
  `overlap=2` растянул бы окно до трёх дней при одном queued-блоке).
- `phpstan analyse --memory-limit=1G` — `[OK] No errors`; `pint --dirty --test` — зелёный (22
  файла); `write-site: pre_mutation: allow · pre_final: allow`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 2/9** 🟡 In progress — P4.1/P4.2 закрыты 🟢, P4.3/P4.4/P4.5/P4.9/**P4.6** закрыты 🟠,
  P4.7/P4.8 открыты.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4, очередь остатка —
  **P4.7 → P4.8**.
- **P4.7 — боевой прогон с внешними необратимыми эффектами** (первый в истории проекта): гейт
  входа D36/D12 (P1/P6 терминальны — да), окно 2–3 дня 2025 из `research/05` §6, живые
  стоп-сигналы С-1…С-7, метрики в трёх точках (§7), калибровка констант дробления по фактам.
  Requires frontier/high (opus/fable) — сессия ЭТОГО захода (sonnet/high) НЕ покрывает тир, item
  не запущен этим заходом.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `/task:plan-design <ID> finish`:
  контракт фазы появился после последнего `finish`, и `plan-protocol` §5 требует сквозного
  reconcile, а не точечной правки. До этого P8 не исполняется (`Exec = plan-design`).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close`
  (`/task:plan-close 2026.07.31-SYS-INTEGRITY P6`) — висящий bookkeeping-хвост, продолжение плана
  НЕ блокирует.
- **Р-8 и Р-4 — за пределами фазы** (`research/05` §3.4): `max_blocks_per_window = 100`
  недостижим, а его ветка при срабатывании теряет ценовой интервал без дробления. Кандидаты в
  `open-questions.md`.
- **Из P4.4/P4.5 (Pending Work items'ов):** `options.records_per_page` (мёртвый дефолт
  `CrawlerFactory`, читателя нет) при правке профиля через форму P4.4 больше не сохраняется —
  `options.columns` единственный managed-ключ; оператор, отредактировавший профиль в админке,
  теряет правку при повторном `db:seed --class=CrawlerProfileSeeder`.
- **Из P4.9 (Pending Work item'а):** `IKZ_COLUMN` — фактический заголовок пробы БЕЗ суффикса
  «(ИКЗ)», вне четырёх констант П-6, не правился; `okpd2_code` на реальной фикстуре легально
  `null` (источник не заполняет классификацию на этом срезе данных) — не дефект маппера.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей
  паспорта (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не
  вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4);
  `RerunRangeAction` work_type_id preserve-ветка без теста (P5.5); систематический timeout
  `EST-XLSX-KS2-001` (P5.7); партиционный дрейф `pg_partman` (`prc_events`, дочинивается P7.4),
  `memory_limit`-конфликт контейнера `agelto_app`, нерасследованный arch-тест
  `CoreKnowsNoDocumentTypeTest` (P5.8); четыре потребителя конфиг-ключа диска в обход резолвера
  (Pending Work P6.3).

**Sources of truth:**

- `phases/P4.md` — Phase Context, контракт фазы, ТЗ P4.1–P4.9, P4.6 Completion Notes/Known
  Deviations (полный разбор трёх диффов вне `Files` и протокола красной пробы).
- `phases/P8.md` — скелет новой фазы с контракт-блоком; предмет, что уже есть в проекте и чего нет.
- `research/05-change-tracking.md` — §3.1 (закрытая группа А) · §6–8 (план прогона P4.7,
  стоп-сигналы, метрики, калибровочные вопросы) — прямой вход следующего item'а.
- `findings/P4-eis-recon.md` §2 — подтверждение update-параметров, снявшее метку экстраполяции.
- `findings/P4-csv-headers.md` + `artifacts/eis-csv-headers.json` — живая проба P4.9 (раскладка
  колонок и резолв карточки, закрыты).
- `brief/01-refinements.md` блок 2026-08-05 — вход владельца: обе развилки и новое требование по
  деградированным сметам.
- `plan.md` §5 D118–D122 — решения захода `plan-design`, предшествовавшего P4.9/P4.6.

**Open risks:**

- **`Классификация по ОКПД2` пуста у ВСЕХ строк архивного среза 500-строчного блока пробы** —
  источник не заполняет классификацию на уровне листинга для завершённых закупок 44-ФЗ этого
  окна; числовой масштаб снимается только боевым прогоном P4.7.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты; чинится инструментовкой
  P7.4.
- **Сертификат ЕИС не проверяется дефолтным набором корней** (`ERR_CERT_AUTHORITY_INVALID` —
  российский УЦ). Для боевого Guzzle нужна политика (Р-10) — стоп-сигнал С-5 плана прогона.
  Владелец 2026-08-04: ставить сертификаты только если начнутся проблемы.
- **Дневной темп сдвига выдачи не измерен** — час delta-scan (02:00 МСК) выбран по единственному
  ночному измерению, K-7; величина `window_overlap_days=2` — стартовая, калибруется на прогоне
  (K-5).
- **223-ФЗ проработан только как листинг** — детальная стадия требует `noticeGuid`, которого в CSV
  нет, способ добора разведкой не проверялся (Р-11).
- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`); перевод
  корпуса эталонов на S3 сломает `GoldCorpusBuilder` (`:162` вызывает `->path()`).
- **10 упавших тестов приёмочного прогона** (подтверждено на `d297d44`, тот же список имён, что
  документированная база): `pg_partman` (`prc_events`, 4), `CoreKnowsNoDocumentTypeTest` (2),
  `permission denied` на `resources/` (`PromptApplyTest`, 2), `CostReportTest` (1), `memory_limit`
  `TextGridRendererTest` (1).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7).
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` падает на интерполяции, обход
  `docker exec agelto_app …`.
- **PHPStan в контейнере `agelto_app` падает на дефолтном 256M лимите** — обход `--memory-limit=1G`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `phpstan analyse
  --memory-limit=1G`; `ignoreHTTPSErrors` для разведки ЕИС (сертификат); `Registry::getAttribute
  ('code')` вместо `->code` в новом коде (докблок сломал бы существующую baseline-запись).
- **Deferred:** детализация фазы P8 и повторный `finish`; `## Phase Handoff` P2/P5 и формальный
  `plan-close` P6; Р-8 (safety-cap ценового дробления) и Р-4 (ловушка стартового стека `0:0`);
  детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
  `config('crawler.filters')`; докблок `@property string $code` у `Registry` + парная чистка
  baseline; переименование `crawler.archive.date_format` → `crawler.eis.date_format`; писатель
  `verified_at`/`verified_by`; писатель пяти колонок `source_*`; прокидка семи старых полей
  паспорта в `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из
  `code`; типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх
  дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction`
  `replaceResources()`; научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку
  `work_type_id`; персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф
  `pg_partman`, `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба
  мёртвого диска `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; сохранение других ключей
  `options` сверх `columns` при правке профиля через форму; историческая витрина числа дублей
  блока (D122 — счётчик живёт в `FetchResult` и логе, колонки в `prc_fetches` нет); заголовок
  `IKZ_COLUMN` без суффикса «(ИКЗ)» (P4.9 Pending Work); систематически пустой `okpd2_code` на
  архивном срезе — числовой масштаб снимается P4.7; включение `delta_scan_enabled` в боевое
  расписание (после P4.7, решение владельца); калибровка `window_overlap_days`/`delta_scan_cron`
  по фактам P4.7 (K-5/K-7).
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
