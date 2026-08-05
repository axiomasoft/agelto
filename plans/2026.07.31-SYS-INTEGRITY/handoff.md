# HANDOFF — 2026-08-05 — after P4.5

**Next:** ре-дизайн ТЗ P4.6 (перечень правок `research/05` §3.3 не покрыт текущим `Files`) —
владелец решил его раньше batch B3, приоритет не изменился.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — открытые design-решения (перечень правок, состав `Files`, class миграции) |
| Context | NEW SESSION — шаг-не-item |
| Суть | Пересобрать ТЗ P4.6: покрыть `Files` группой Б перечня (`DeltaScanProcessor`, `RunDeltaScan`, `CrawlerRunManager`, `CsvBlockIngestor`, `EisCsvRowMapper`, `CsvSourceMethod`, `EisProcedureType` + их тесты) |
| Команда | `/task:plan-design 2026.07.31-SYS-INTEGRITY P4.6` |

**Сделано этим заходом (`plan-exec`, sonnet/medium, 2026-08-05) — серия B3 целиком.**

- **P4.4 закрыт 🟠**, item-коммит `20d2e6a` (3 файла, `+363/−6`), bookkeeping `acb9bcf`.
  `CrawlerForm`: реактивная секция «Фильтр площадки» резолвит класс через `CrawlerFilterFactory`
  (та же карта, что P4.3 — второго списка «какие поля у ЕИС» нет), мультиселект 25 флагов колонок
  CSV с тремя заблокированными обязательными (`registryNumberCsv`/`publishDateCsv`/
  `lastDateChangeCsv` — объединяются на сохранении, даже если UI как-то обойдёт disable),
  cron-валидация расписания (`Cron\CronExpression`, пакет уже в vendor), read-only превью боевого
  URL через реальный `EisCsvUrlBuilder` на неперсистентном `Crawler`. `CrawlersTable` — колонки
  `mode`/`schedule`. Тесты (`CrawlerFormTest`, 4) — зелёные. «Ручная проба в админке» из Validation
  заменена эквивалентным `Livewire::test()`-прогоном (нет живой браузерной GUI-сессии в этом
  окружении) — Known Deviations item'а.
- **P4.5 закрыт 🟠**, item-коммит `5ab05b2` (4 файла, `+366`), bookkeeping `e94f16f`.
  `EisPlatformSeeder` — площадка `code=eis` + реестр `fz44` с `settings` (`procedure_url_types`
  9 меток, `card_url_templates` 6×4, `ea615`/`po615` без `supplier-results`) буквально из
  `artifacts/eis-registry-settings.json`. `CrawlerProfileSeeder` — архивный (`stageCompleted`,
  `is_active=true`) и fresh (`filter=null`, `is_active=false`, D37) профили, 13 флагов колонок
  общей константой. Порядок в `DatabaseSeeder`: площадка/реестр → `StatusMappingSeeder` → профили.
  Тесты (`EisSeedersTest`, 7) — зелёные; дважды прогнан `db:seed` на dev-БД вручную — 2 профиля оба
  раза, `StatusMappingSeeder` без warn. Мелкая находка: `database/seeders/Procurement/` на входе
  item'а фактически не существовала (план утверждал «уже существует, пустая») — создана вместе с
  первым файлом, эскалация не требовалась (Pending Work item'а).
- **Оба item'а 🟠 по одной и той же внешней причине**: Validation-клауза «полный сюит зелёный» не
  выполнена буквально — 10 падений побайтово совпадают с документированной базой (`pg_partman`-дрейф
  ×4, `CoreKnowsNoDocumentTypeTest` ×2, `PromptApplyTest` ×2, `CostReportTest` ×1,
  `TextGridRendererTest` ×1), диффа обоих item'ов среди затронутых файлов нет — тот же случай, что
  P4.3/P5.8. Канонический `phpstan analyse` (`paths: [app]`) — 0 ошибок на обоих item'ах; сидеры и
  тесты вне зоны анализа проекта (`docs/03_Dev/static-analysis.md`).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 2/8** 🟡 In progress — P4.1/P4.2 закрыты 🟢, P4.3/P4.4/P4.5 закрыты 🟠.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: ре-дизайн ТЗ P4.6 → P4.6
  (механизм + раскладка) → P4.7 (боевой прогон) → P4.8 (доки и приёмка).
- **ТЗ P4.6 невыполнимо в нынешнем виде** — `Files` не покрывает `DeltaScanProcessor`,
  `RunDeltaScan`, `CrawlerRunManager`, `CsvBlockIngestor`, `EisCsvRowMapper`, `CsvSourceMethod`,
  `EisProcedureType` и их тесты; группа Б перечня (4 позиции, блокеры P4.7) — кандидат в отдельный
  item ПЕРЕД P4.6.
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close`
  (`/task:plan-close 2026.07.31-SYS-INTEGRITY P6`) — висящий bookkeeping-хвост, продолжение плана
  НЕ блокирует.
- **Р-8 и Р-4 — за пределами фазы** (`research/05` §3.4): `max_blocks_per_window = 100`
  недостижим, а его ветка при срабатывании теряет ценовой интервал без дробления. Кандидаты в
  `open-questions.md`.
- **Новое из P4.4/P4.5 (Pending Work items'ов):** `options.records_per_page` (мёртвый дефолт
  `CrawlerFactory`, читателя нет) при правке профиля через форму P4.4 больше не сохраняется —
  `options.columns` единственный managed-ключ; оператор, отредактировавший профиль в админке,
  теряет правку при повторном `db:seed --class=CrawlerProfileSeeder` (сидер перезаписывает
  безусловно, как `StatusMappingSeeder`/`AiRoutingSeeder`).
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не вызывает
  `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4); `RerunRangeAction`
  work_type_id preserve-ветка без теста (P5.5); систематический timeout `EST-XLSX-KS2-001` (P5.7);
  партиционный дрейф `pg_partman` (`prc_events`), `memory_limit`-конфликт контейнера `agelto_app`,
  нерасследованный arch-тест `CoreKnowsNoDocumentTypeTest` (P5.8); четыре потребителя конфиг-ключа
  диска в обход резолвера (Pending Work P6.3).

**Sources of truth:**

- `research/05-change-tracking.md` — синтез P4.2 (слой 3): gap-таблица, перекрытие и календарь,
  перечень правок P4.6, контракт фильтра (раздел 4 — исполнен P4.3), две таблицы профилей
  (раздел 5 — исполнены P4.4/P4.5), план прогона, метрики, калибровочные вопросы.
- `findings/P4-eis-recon.md` — фактура ЕИС 2026-08-04 (слой 1: факты, без проектных выводов).
- `artifacts/eis-filter-params.json` — параметры, 25 флагов колонок — источник `COLUMN_FLAG_LABELS`
  (`CrawlerForm`) и `COLUMNS` (`CrawlerProfileSeeder`).
- `artifacts/eis-registry-settings.json` — `procedure_url_types`/`card_url_templates`, буквально
  перенесён в `EisPlatformSeeder`.
- `app/Dto/Procurement/Crawler/EisSearchFilter.php` + `config('crawler.filters')` — контракт
  фильтра в коде; `app/Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php` —
  его Filament-поверхность; `database/seeders/Procurement/{EisPlatformSeeder,CrawlerProfileSeeder}.php`
  — боевые данные площадки/реестра/профилей.
- `research/eis-csv-recon.md`, `research/eis-card-recon.md` — исторические артефакты 2026-07-03
  (не правятся; расхождения — в новых документах).
- `findings/repo-recon-2026-07-31.md` §10 — состояние кроулинг-контура.
- `phases/P4.md` — Phase Context, контракт фазы, ТЗ P4.1–P4.8.
- `roadmap.md` — очередь фаз (D63) и launch-блок батча B3 (исполнен, роль исчерпана).

**Open risks:**

- **P4.6 в нынешнем ТЗ невыполним** — половина перечня правок лежит вне его `Files`.
- **Детальная стадия на сегодняшнем дереве не снимет ни одной карточки** — Р-1 + Р-12
  (`procedure_type_raw` не читается и резолвится не тем ключом); НМЦК не заносится (Р-13).
  Адрес — группа Б перечня `research/05` §3.2.
- **Р-14/Р-15 на уровне «вероятно»** — заголовки колонок ОКПД2 и заказчика выведены из подписи
  дерева модалки, не процитированы из CSV; снимаются одной пробой на P4.6/P4.7.
- **Сертификат ЕИС не проверяется дефолтным набором корней** (`ERR_CERT_AUTHORITY_INVALID` —
  российский УЦ). Для боевого Guzzle нужна политика (Р-10) — стоп-сигнал С-5 плана прогона.
  Владелец 2026-08-04: ставить сертификаты только если начнутся проблемы.
- **Единственный RU-узел в пуле** (id=1): его бан останавливает и разведку, и боевой прогон P4.7.
- **Дневной темп сдвига выдачи не измерен** — разведка шла ночью (голова блока держалась
  ≈17 минут). Час delta-scan (02:00 МСК) выбран по этому единственному измерению — K-7.
- **223-ФЗ проработан только как листинг** — детальная стадия требует `noticeGuid`, которого в
  CSV нет, способ добора разведкой не проверялся (Р-11).
- **`estimates_s3` объявлен и никем не адресуется** (`config/filesystems.php:74-85`).
- **Перевод корпуса эталонов на S3 сломает `GoldCorpusBuilder`** (`:162` вызывает `->path()`).
- **10 упавших тестов приёмочного прогона** (подтверждено на `111320d`, `77265b3`, `20d2e6a`,
  `5ab05b2` — тот же список): партиционный дрейф `pg_partman` (`prc_events`, 4), arch-тест
  `CoreKnowsNoDocumentTypeTest` (2), `permission denied` на `resources/` (`PromptApplyTest`, 2),
  `CostReportTest` (1), `memory_limit` `TextGridRendererTest` (1).
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
- **Deferred:** `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-8 (safety-cap ценового
  дробления) и Р-4 (ловушка стартового стека `0:0`); детальная стадия 223-ФЗ (Р-11); резервный
  RU-прокси-узел; `fz223` в карте `config('crawler.filters')`; докблок `@property string $code`
  у `Registry` + парная чистка baseline; переименование `crawler.archive.date_format` →
  `crawler.eis.date_format`; писатель `verified_at`/`verified_by`; писатель пяти колонок
  `source_*`; прокидка семи старых полей паспорта в `ExtractionOrchestrator`; материализация
  не-`Item` строк; тип нормы аксессором из `code`; типизированный носитель `RowType` (OQ-6);
  переписывание текстов промтов сверх дедупликации (OQ-7); партиционирование
  `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`; научная нотация в
  `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`; персистентный подъём
  `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman`, `memory_limit`-конфликт
  `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска `estimates_s3`;
  колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод `FormatBenchRunner`/
  `GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на `FileLocationResolver`; реальное
  удаление объектов на исходном диске после окна отката; операторская правка `options.
  records_per_page` через форму профиля (мёртвый ключ, читателя нет — Pending Work P4.4);
  сохранение других ключей `options` сверх `columns` при правке профиля через форму.
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
