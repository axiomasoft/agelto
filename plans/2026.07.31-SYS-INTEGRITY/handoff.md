# HANDOFF — 2026-08-05 — after P4.3

**Next:** серия B3 — P4.4 (Filament-поверхность фильтра) и P4.5 (сиды площадки, реестра и двух
профилей) одной сессией по замороженному контракту P4.3

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписанная поверхность по замороженному контракту |
| Context | continue (/clear) — ручной item |
| Суть | Форма профиля со специфичными для реестра полями + сиды площадки ЕИС/`fz44`/двух профилей |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P4.4 P4.5` |

**Ре-дизайн ТЗ P4.6 по-прежнему нужен** — он не блокирует B3, но блокирует сам P4.6 (половина
перечня правок `research/05` §3.3 лежит вне его `Files`). Владелец вправе сделать его первым:

```
/task:plan-design 2026.07.31-SYS-INTEGRITY P4.6
```

**Сделано этим заходом (`plan-run`, opus/high, 2026-08-05).** Item-коммит `77265b3` — 9 файлов,
`+475/−19`; bookkeeping — отдельным коммитом. P4.3 закрыт **🟠 Done with deviations**.

- **`EisSearchFilter`** (`app/Dto/Procurement/Crawler/`, 119 строк) — `Data`-класс на 10 полей
  контракта `research/05` §4; параметры со статусом «не установлено» в контракт не вошли. Адрес —
  по правилу `research/04` §2.5 (D65), `DomainLayoutTest` зелёный.
- **Фильтр соответствует площадке.** Класс резолвится по коду реестра через карту
  `config('crawler.filters')` (`CrawlerFilterFactory`, механика `SourceMethodFactory`): другая
  площадка/реестр получает СВОЙ `Data`-класс строкой в карте, а не общий мешок ключей.
  `match`-лестницы и `if (code === 'fz44')` в билдере нет.
- **Отказ на неизвестном ключе — в двух точках:** `validateAndCreate()` (явная проверка против
  `ALLOWED_KEYS`: spatie лишние ключи игнорирует молча) и хук `saving` модели, чтобы опечатка
  оператора не доживала до боевого запроса. `priceTo=0` запрещён правилом `min:1` (Р-4: он
  обнуляет выдачу, а не снимает ограничение).
- **Схема БД не тронута** (D34): `prc_crawlers.filter` остаётся `jsonb`, ключи в БД — имена полей
  DTO, как в таблицах профилей `research/05` §5.2. Миграций нет.
- **`EisCsvUrlBuilder` собирает query из типа**; имена параметров ЕИС и формат даты остались
  только в нём. Выключенный этап и незаполненное поле ключа не эмитят.
- **Находка (Implementation Rules п. 5): два теста фиксировали НЕСУЩЕСТВУЮЩИЕ параметры ЕИС** —
  `okpd2`/`priceFrom` в боевом URL (правильные — `okpd2Ids` со статусом «не установлено» и
  `priceFromGeneral`). Это ровно та опечатка, ради которой заводился контракт; оба теста
  переведены на контрактные ключи.
- **Тесты:** новый `CrawlerFilterContractTest` (7), `EisCsvUrlBuilderTest` 9 → 13.

**Почему 🟠, а не 🟢:**

1. **Дифф вне `Files`** — `tests/Feature/Crawler/CsvSourceMethodTest.php` (`+2/−2`): без правки
   полный сюит краснел бы диффом item'а, но файл в `Files` не перечислен.
2. **Клауза «полный сюит зелёный» не выполнена** — `10 failed, 1 skipped, 1014 passed`. Диффом
   item'а НЕ вызвано: `git stash` всего диффа P4.3 и прогон на чистом `111320d` дают **тот же
   список из 10 падений** (`1003 passed`). Все десять уже стоят в Open risks ниже.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 2/8** 🟡 In progress — P4.1/P4.2 закрыты 🟢, P4.3 закрыт 🟠.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: B3 (P4.4 + P4.5) →
  ре-дизайн ТЗ P4.6 → P4.6 (механизм + раскладка) → P4.7 (боевой прогон) → P4.8 (доки и приёмка).
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
- **Новое из P4.3 (Pending Work item'а):** `CrawlerForm` остаётся на `KeyValue` до P4.4; докблок
  `@property string $code` у `Registry` требует парной чистки baseline-записи `EisCardUrlBuilder`;
  формат даты ЕИС живёт под archive-специфичным ключом `config('crawler.archive.date_format')`;
  `fz223` в карте фильтров отсутствует по решению `research/05` §5.4.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5); `EstimateValidator::bcadd()` научная нотация (P5.4); `RerunRangeAction` не вызывает
  `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без писателя (B-4); `RerunRangeAction`
  work_type_id preserve-ветка без теста (P5.5); систематический timeout `EST-XLSX-KS2-001` (P5.7);
  партиционный дрейф `pg_partman` (`prc_events`), `memory_limit`-конфликт контейнера `agelto_app`,
  нерасследованный arch-тест `CoreKnowsNoDocumentTypeTest` (P5.8); четыре потребителя конфиг-ключа
  диска в обход резолвера (Pending Work P6.3).

**Sources of truth:**

- `research/05-change-tracking.md` — синтез P4.2 (слой 3): gap-таблица, перекрытие и календарь,
  перечень правок P4.6, **контракт фильтра (раздел 4 — исполнен P4.3)**, две таблицы профилей
  (раздел 5 — вход P4.4/P4.5), план прогона, метрики, калибровочные вопросы.
- `findings/P4-eis-recon.md` — фактура ЕИС 2026-08-04 (слой 1: факты, без проектных выводов).
- `artifacts/eis-filter-params.json` — параметры, 25 флагов колонок, встречная сверка с кодом.
- `artifacts/eis-registry-settings.json` — `procedure_url_types`/`card_url_templates` под сид P4.5
  (ключи — СЫРЫЕ строки CSV, см. Р-12).
- `app/Dto/Procurement/Crawler/EisSearchFilter.php` + `config('crawler.filters')` — контракт
  фильтра в коде; источник истины по допустимым значениям для формы P4.4 один, и он в DTO.
- `research/eis-csv-recon.md`, `research/eis-card-recon.md` — исторические артефакты 2026-07-03
  (не правятся; расхождения — в новых документах).
- `findings/repo-recon-2026-07-31.md` §10 — состояние кроулинг-контура.
- `phases/P4.md` — Phase Context, контракт фазы, ТЗ P4.1–P4.8.
- `roadmap.md` — очередь фаз (D63) и launch-блок батча B3.

**Open risks:**

- **P4.6 в нынешнем ТЗ невыполним** — половина перечня правок лежит вне его `Files`.
- **`CrawlerForm` до P4.4 несовместим с типизированным фильтром** — `KeyValue::make('filter')`
  получит `Data`-объект. Пока профиля с непустым фильтром в БД нет (первый заводит сид P4.5,
  идущий ПОСЛЕ P4.4), окно закрыто порядком items; смена порядка его открывает.
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
- **10 упавших тестов приёмочного прогона** (подтверждено на `111320d` и на `77265b3` — тот же
  список): партиционный дрейф `pg_partman` (`prc_events`, 4), arch-тест
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
  удаление объектов на исходном диске после окна отката.
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
