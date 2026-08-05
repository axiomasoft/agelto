# HANDOFF — 2026-08-05 — after P4.13

**Next:** `/task:plan-run 2026.07.31-SYS-INTEGRITY P4.14` (Exec=manual, implementation/high — та же
модель/effort, что уже несёт эта сессия, переключение `/model`/`/effort` не требуется)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Детальный слой карточки: диагноз Д-6/Д-7 по HTML, снятому P4.13, и правка `EventJournalParser`/`CommonInfoParser`/`PartiesParser` под фактическую вёрстку |
| Команда | — |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.14
```

**Сделано этим заходом (`plan-run P4.13`, sonnet/high, 2026-08-05).**

- **P4.13 закрыт 🟠**, item-коммит `40b928a`: живая проба ВСЕХ четырёх вкладок карточки
  `prc_procurements` id=18151 (regNumber `0338200009125000001`, `ea20`) через RU-прокси
  `prc_proxies` id=1 (тот же узел, что P4.1/P4.9/P4.12) — egress проверен ДО первого запроса
  (D3). Сырой HTML — `artifacts/eis-card-html/{documents,common-info,event-journal,
  supplier-results}.html`, раскладка — `findings/P4-card-layout.md` (вход P4.14). Найдена и
  обезличена ДО коммита персональные данные физлица (ФИО/email/телефон) на вкладке
  `common-info.html`.
- `DocumentsParser` приведён к фактической вёрстке вкладки `documents`: `div.blockFilesTabDocs`
  → `div.attachment` → `span.section__value > a[href^=".../filestore/..."]`, имя файла — атрибут
  `title`. `uid` в query-строке сохранился без изменений — `externalIdFromHref()` не тронут;
  `absoluteUrl()` не тронут (подтверждено чтением кода до пробы). `kind` — легитимный `null` (у
  новой вёрстки нет колонки-категории документа). Тихий отказ снят: `catch` логирует + отдельно
  логируется несоответствие «есть ссылки filestore, распознано 0». Фикстура пересобрана из
  реального (усечённого) тела — 6 документов вместо 2 синтетических.
- **K=0** (полный сюит `--no-tia` на item-коммите: список упавших тестов байт-в-байт идентичен
  дереву ДО диффа item'а). Попутно вскрыта устаревшая база: `handoff`/Execution Rules несли
  «10 упавших», фактически уже **14** (добавились `GeminiProxyRoutingTest` ×1 и
  `StorageLifecycleTest` ×3 — оба подтверждены НЕ этим диффом) — см. Pending Work ниже.
- Дифф вне `Files` (Known Deviation, material → 🟠): три уже-зелёных теста
  (`DocumentIndexerTest`, `DetailAnalysisProcessorTest`, `FetchDetailsTest`) держали жёсткие
  предположения о старой 2-документной синтетической фикстуре — поправлены механически
  (`FetchDetailsTest` получил СВОЙ локальный `.pdf`-only HTML вместо общей 6-документной
  фикстуры — иначе реальные расширения `.xls`/`.doc`/`.docx`/`.xml` заводят вложения, которые
  `SpreadsheetStrategy`/`WordStrategy` реально пытаются распарсить и падают на фейковом бинарном
  теле `Http::fake()`; это by-design поведение `PipelineJob`/`DocumentPreparer`, не баг домена, и
  вне Scope P4.13).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 5/15** 🟡 In progress — терминальны 12 из 15 (P4.1 🟢, P4.2 🟢, P4.3 🟠, P4.4 🟠, P4.5 🟠,
  P4.9 🟠, P4.6 🟠, P4.7 🟠, P4.10 🟢, P4.11 🟢, P4.12 🟢, P4.13 🟠); открыты P4.14–P4.15 и P4.8.
- **P7 0/8** ⬜ Not started — ждёт терминальной P4.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: соло `P4.14 → P4.15 → P4.8`.
- **P4.14 — Exec=manual** (implementation/high, D118-класс: диагноз Д-6/Д-7 по РЕАЛЬНОМУ HTML,
  снятому P4.13, а не по гипотезе) — та же модель/effort, что уже несёт эта сессия:
  `/task:plan-run 2026.07.31-SYS-INTEGRITY P4.14` запускается БЕЗ переключения
  `/model`/`/effort`.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5: контракт
  фазы P8 появился после последнего `finish`). Контракт P4 расширен (Производит — документный
  контур, вёрстка вкладок, CA-бандл), и это второе основание для повторного `finish` перед
  закрытием плана.
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** — сознательно отложены (D126), решение
  пересматривается ПОСЛЕ того, как контур доказан контрольным прогоном P4.15.
- **База «упавших тестов» полного сюита устарела** (документированные 10 → фактические 14 на
  дереве после P4.13): `GeminiProxyRoutingTest` (1 — ассертация не обновлена после P4.11/Д-4,
  URL-кодирование кредов прокси теперь корректно) и `StorageLifecycleTest` (3 — `mc alias set`
  падает на кредах MinIO окружения) — оба подтверждены `git stash` как НЕ вызванные диффом P4.13.
  Следующий item, трогающий полный сюит, обязан считать от **14**, не от 10.

**Sources of truth:**

- `research/10-p4-remediation.md` §3.6–§3.7 — вердикты Д-5/Д-6/Д-7 и раскрой ремедиации.
- `findings/P4-load-baseline.md` §5, §3.6 — фактура боевого прогона, кандидат карточки.
- `findings/P4-card-layout.md` — фактическая вёрстка ВСЕХ четырёх вкладок (новый, вход P4.14).
- `artifacts/eis-card-html/*.html` — сырой HTML вкладок (новый, вход P4.14).
- `phases/P4.md` — Phase Context, P4.10–P4.13 закрыты, items P4.14–P4.15 целиком.
- `plan.md` §3 Routing (строки P4.10–P4.15, батч B4), §5 D123–D128.
- `roadmap.md` — карта исполнения фазы.
- `app/Services/Procurement/Eis/Card/DocumentsParser.php` — новый канон разбора вкладки
  `documents`.

**Open risks:**

- **Детальный слой карточки пуст** (Д-6/Д-7) — до закрытия P4.14 журнал событий и
  common-info/parties/items/financing не читаются с боевых данных; это следующий, а не последний
  открытый риск (после него — P4.15 контрольный прогон).
- **Документный контур починен в коде** (P4.13), но НЕ подтверждён контрольным прогоном на живых
  данных — это делает P4.15.
- **Идемпотентность инжеста и три тихих дефекта контура прогона ПОЧИНЕНЫ в коде** (P4.10/P4.11),
  граница доверия TLS персистентна (P4.12) — перекрытие окон/delta-scan остаются выключенными
  (D126) до контрольного прогона P4.15; уже занесённые прогонами #14/#15 дубли снимаются только
  очисткой в P4.15.
- **`agelto_scheduler` остановлен** намеренно: активный faker-профиль `prc_crawlers#1` ходит в ЕИС
  каждые 15 минут и портит здоровье узла. Поднимать — после деактивации профиля.
- **Данные под `registry_id = 1`** (17 942 закупки прогонов #14/#15) остаются в БД до P4.15 —
  любой запрос к `prc_procurements` до очистки видит их вперемешку с корректными.
- **Диагноз Д-7 — гипотеза**, а не факт до P4.14: причина пустой `prc_eis_details` в findings не
  разбиралась; P4.13 сняла HTML для диагноза, сам диагноз ещё не поставлен.
- **Два типа процедуры вне `procedure_url_types`** реестра ЕИС (аудит бухотчётности — 11 закупок,
  ТКО — 3): 14 из 8 584 = 0.16 % теряют детальную стадию.
- **Стартовый ценовой стек строится от потолка, а не от распределения**: 180 блоков вместо
  теоретических 29 (6.2×). Правка алгоритмическая, прогон не блокирует — follow-up.
- **`Классификация по ОКПД2` пуста у 100 % архивного среза** (8 584 из 8 584) — свойство листинга
  завершённых закупок, не выборочный пробел.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты, дефект расширяется день
  ото дня (календарный дрейф теста); чинится инструментовкой P7.4.
- **База «упавших тестов» полного сюита устарела** (10 документированных → 14 фактических на
  дереве после P4.13; детали — Remaining выше).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  дополнительное основание запрета `migrate:fresh` в P4.15.
- **`estimates_s3` объявлен и никем не адресуется**; перевод корпуса эталонов на S3 сломает
  `GoldCorpusBuilder`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` падает на интерполяции, обход
  `MINIO_ROOT_PASSWORD=<любое> docker compose <cmd>` (build/up) либо `docker exec agelto_app …`;
  та же причина стоит за `StorageLifecycleTest` (3 падения полного сюита).
- **PHPStan в контейнере падает на дефолтном 256M** — обход `--memory-limit=1G`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `MINIO_ROOT_PASSWORD=<любое>
  docker compose build/up` для команд, которым обязателен `docker compose` (build/recreate);
  `phpstan analyse --memory-limit=1G`; `agelto_scheduler` остановлен; `Registry::getAttribute('code')`
  вместо `->code` в новом коде.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126 — после P4.15);
  персистентность корня для ОСТАЛЬНЫХ каналов площадок за пределами `zakupki.gov.ru`; два типа
  процедуры вне `procedure_url_types`; стартовый ценовой стек от распределения; детализация фазы
  P8 и повторный `finish`; `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-4 (ловушка
  стартового стека `0:0`); детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в
  карте `config('crawler.filters')`; докблок `@property string $code` у `Registry`; переименование
  `crawler.archive.date_format` → `crawler.eis.date_format`; писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`;
  научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска
  `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; историческая витрина числа
  дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)»; актуализация базы «упавших тестов»
  полного сюита (10 → 14) в Execution Rules/следующем handoff; `GeminiProxyRoutingTest`-assertion
  под URL-кодирование кредов P4.11; креды `mc`/MinIO окружения (`StorageLifecycleTest`).
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
