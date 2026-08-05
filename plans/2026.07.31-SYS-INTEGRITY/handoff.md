# HANDOFF — 2026-08-05 — after P4.14

**Next:** «ЗАПУСК ВРУЧНУЮ: fable|opus/high» — P4.15 (Exec=manual, frontier/high — ВЫШЕ этой сессии,
`/task:plan-run` пинит sonnet/high и по Routing-гейту обязан отклонить такой item)

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Контрольный прогон: очистка данных первого прогона (`registry_id = 1`, 17 942 закупки), перезалив трёх дней архивного профиля через фиксы P4.10–P4.14, пере-baseline метрик БД для P7.3 |
| Команда | — |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.15
```

**Сделано этим заходом (`plan-run P4.14`, sonnet/high, 2026-08-05).**

- **P4.14 закрыт 🟠**, item-коммит `c6cd6b4`: диагноз Д-7 поставлен ФАКТОМ по HTML, снятому
  P4.13 (`findings/P4-card-layout.md`), для всех пяти блоков детального слоя.
  - `EventJournalParser`: старый селектор `div.event-journal table.event-journal-table` — 0
    совпадений; факт — `table#event.displaytagTable`. Фикс: селектор `table#event`, строки/ячейки
    уже были generic. Тихий отказ снят (`warnIfJournalEvidenceSuppressed`).
  - `CommonInfoParser`: `dt`/`dd` — 0 `<dt>` на странице целиком; факт — плоские пары
    `section.blockInfo__section` > `span.section__title` + `span.section__info`. Единый помощник
    `titleSection()`/`readDefinitionValue()`/`infoNode()` заменил три локальных костыля.
    Финансирование — таблица «год-колонка», не «год-строка»: `parseFinancingRows()` транспонирует.
    Классификатор позиции — ОДИН код на ячейку (не пара «ОКПД2/КТРУ» через слэш):
    `splitClassifierCode()` переписан на дефис-детект КТРУ. Тихий отказ снят
    (`warnIfBlockInfoSuppressed`).
  - `PartiesParser`/`SupplierResultsParser` — **НЕТРОНУТЫ**: `organizations-block`/
    `table.parties-table` отсутствует на ОБЕИХ кандидатных вкладках, success-путь
    `supplier-results` (протокол+заявки+победитель) не снят ни на одной карточке (доступна
    только карточка НЕСОСТОЯВШЕЙСЯ закупки) — Implementation Rules item'а п. 2/5 запрещают правку
    без фактуры. `[UNVERIFIED]` в Pending Work — см. `phases/P4.md` P4.14 Completion Notes.
  - K-6 закрыт (`research/05-change-tracking.md` §8): пояс «Дата обновления» CSV — **МСК**
    (хронологическая сверка `source_updated_at` против `event-journal`, детали — Completion
    Notes).
  - Фикстуры пересобраны из реальных тел: `event-journal.html` 4288 байт (было 136 614) ·
    `common-info.html` 10 773 байта (было 170 418) · `supplier-results.html` 4310 байт (было
    152 215).
- **K=0** (полный сюит `--no-tia` на item-коммите `c6cd6b4`: **13 упавших**, на ОДИН МЕНЬШЕ базы
  **14** — список идентичен базовому за вычетом одной строки, `DetailAnalysisProcessorTest > run
  наполняет…` починен мимоходом). Новая база для следующего item'а, трогающего полный сюит: **13**.
- Дифф вне `Files` (Known Deviation, material → 🟠): шесть тестов-потребителей общих фикстур
  common-info/event-journal сломались реальными данными, поправлены механически (тот же класс, что
  прецедент P4.13) — `SupplierResultsProcessorTest`/`CommonInfoProcessorTest`/
  `OrganizationPartiesProcessorTest` сняты с общей фикстуры на локальный синтетический HTML;
  `EventJournalProcessorTest`/`DetailAnalysisProcessorTest`/`FetchDetailsTest` — date-shift-хелперы
  обновлены на реальные литералы дат (`23.01.2025`/`15.01.2025`, были `30.06.2026`/`11.07.2026`);
  `DetailAnalysisProcessorTest` попутно поправлены два пред-существующих латентных дефекта
  (`Document::count()` 2→6, честные `toBeFalse()` для parties/protocol на реальных данных).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 5/15** 🟡 In progress — терминальны 13 из 15 (P4.1 🟢, P4.2 🟢, P4.3 🟠, P4.4 🟠, P4.5 🟠,
  P4.9 🟠, P4.6 🟠, P4.7 🟠, P4.10 🟢, P4.11 🟢, P4.12 🟢, P4.13 🟠, P4.14 🟠); открыты P4.15 и P4.8.
- **P7 0/8** ⬜ Not started — ждёт терминальной P4.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: соло `P4.15 → P4.8`.
- **P4.15 — Exec=manual** (frontier/high, D123/D124/D126-класс: боевой прогон с внешними
  необратимыми эффектами + необратимое удаление данных первого прогона) — ВЫШЕ этой сессии
  (sonnet/high): `/model opus` + `/effort high` + `/task:plan-run 2026.07.31-SYS-INTEGRITY P4.15`.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5: контракт
  фазы P8 появился после последнего `finish`).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** — сознательно отложены (D126), решение
  пересматривается ПОСЛЕ того, как контур доказан контрольным прогоном P4.15.
- **База «упавших тестов» полного сюита обновлена** (10 → 14 после P4.13 → **13** после P4.14,
  попутно починен `DetailAnalysisProcessorTest > run наполняет…`): `GeminiProxyRoutingTest` (1),
  `StorageLifecycleTest` (3) и ещё девять пред-существующих — следующий item, трогающий полный
  сюит, обязан считать от **13**.
- **`PartiesParser`/`SupplierResultsParser` `[UNVERIFIED]`** — блока-источника нет ни на одной
  снятой карточке; снимается следующим боевым прогоном (P4.15) на КАРТОЧКЕ С СОСТАВОМ СТОРОН/
  определённым победителем, если такая попадёт в перезалитое окно.
- **Бакет «Последующие годы» финансирования не заполняется** — `FinancingRowData::fiscalYear`
  non-nullable в форме DTO (вне Scope P4.14); нужен отдельный item на правку формы DTO +
  `is_beyond_horizon`.
- **К-6 закрыт диагнозом (МСК), но не кодом** — `EisCsvRowMapper`/`config/crawler.php` не
  приведены к явному указанию пояса; follow-up вне Scope P4.14.

**Sources of truth:**

- `research/10-p4-remediation.md` §3.6–§3.8 — вердикты Д-5/Д-6/Д-7 и раскрой ремедиации P4.15.
- `findings/P4-load-baseline.md` §5, §3.6 — фактура боевого прогона, кандидат карточки.
- `findings/P4-card-layout.md` — фактическая вёрстка ВСЕХ четырёх вкладок (вход P4.14, потреблён).
- `phases/P4.md` — Phase Context, P4.10–P4.14 закрыты, item P4.15 целиком.
- `plan.md` §3 Routing (строка P4.15, батч —), §5 D123–D128.
- `roadmap.md` — карта исполнения фазы.
- `app/Services/Procurement/Eis/Card/{EventJournalParser,CommonInfoParser}.php` — новый канон
  разбора вкладок `event-journal`/`common-info`.
- `research/05-change-tracking.md` §8 — K-6 закрыт (МСК).

**Open risks:**

- **Документный и детальный слой карточки починены в коде** (P4.13/P4.14), но НЕ подтверждены
  контрольным прогоном на живых данных — это делает P4.15.
- **Идемпотентность инжеста и три тихих дефекта контура прогона ПОЧИНЕНЫ в коде** (P4.10/P4.11),
  граница доверия TLS персистентна (P4.12) — перекрытие окон/delta-scan остаются выключенными
  (D126) до контрольного прогона P4.15; уже занесённые прогонами #14/#15 дубли снимаются только
  очисткой в P4.15.
- **`agelto_scheduler` остановлен** намеренно: активный faker-профиль `prc_crawlers#1` ходит в ЕИС
  каждые 15 минут и портит здоровье узла. Поднимать — после деактивации профиля.
- **Данные под `registry_id = 1`** (17 942 закупки прогонов #14/#15) остаются в БД до P4.15 —
  любой запрос к `prc_procurements` до очистки видит их вперемешку с корректными.
- **`PartiesParser`/`SupplierResultsParser` `[UNVERIFIED]`** — состав сторон и success-путь
  результатов торгов не читаются с боевых данных ни на одной снятой карточке; следующий боевой
  прогон (P4.15) может либо подтвердить достаточность фактуры, либо оставить пробел открытым.
- **Два типа процедуры вне `procedure_url_types`** реестра ЕИС (аудит бухотчётности — 11 закупок,
  ТКО — 3): 14 из 8 584 = 0.16 % теряют детальную стадию.
- **Стартовый ценовой стек строится от потолка, а не от распределения**: 180 блоков вместо
  теоретических 29 (6.2×). Правка алгоритмическая, прогон не блокирует — follow-up.
- **`Классификация по ОКПД2` пуста у 100 % архивного среза** (8 584 из 8 584) — свойство листинга
  завершённых закупок, не выборочный пробел.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты, дефект расширяется день
  ото дня (календарный дрейф теста); чинится инструментовкой P7.4.
- **База «упавших тестов» полного сюита обновлена** (14 → 13 после P4.14; детали — Remaining
  выше).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  дополнительное основание запрета `migrate:fresh` в P4.15.
- **`estimates_s3` объявлен и никем не адресуется**; перевод корпуса эталонов на S3 сломает
  `GoldCorpusBuilder`.
- **Бакет «Последующие годы» финансирования не заполняется** (`FinancingRowData::fiscalYear`
  non-nullable) — follow-up вне Scope P4.14.
- **К-6 закрыт диагнозом (МСК), не кодом** — `EisCsvRowMapper` не приведён к явному поясу.
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
  дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)»; `GeminiProxyRoutingTest`-assertion под
  URL-кодирование кредов P4.11; креды `mc`/MinIO окружения (`StorageLifecycleTest`); `PartiesParser`/
  `SupplierResultsParser` под success-путь (нужна фактура следующего прогона); правка формы
  `FinancingRowData::fiscalYear` под бакет «Последующие годы»; явный пояс МСК в `EisCsvRowMapper`
  под К-6.
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
