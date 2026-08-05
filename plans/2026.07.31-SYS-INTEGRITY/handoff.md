# HANDOFF — 2026-08-05 — after P4.8

**Next:** «ЗАПУСК ВРУЧНУЮ: opus/xhigh» — **P7.1** (первый item фазы P7, ревизия схемы БД и
топологии хранения). Фаза P4 терминальна (6/16 🟢, `🟠 Done with deviations`) — по порядку фаз
D63 (`P1 → P2 → P3 → P5 → P6 → P4 → P7`) следующая фаза — **P7**, последняя в очереди.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — предписано §3 Routing (`frontier/high`), P7.1 открывает design-решения по критерию severity ревизии БД |
| Context | continue (/clear) — ручной item |
| Суть | Ревизия схемы БД: критерий severity находок, класс правки каждой, состав P7.5; вердикты замораживают миграции на БД с боевыми данными |

```
/model opus
/effort xhigh
/task:plan-run 2026.07.31-SYS-INTEGRITY P7.1
```

**Сделано этим заходом (`plan-exec P4.8`, sonnet/medium, 2026-08-05).**

- **P4.8 закрыт 🟢 Done**, item-коммит **`e254e23`** (9 файлов, `+368/−3`) — доки домена
  синхронизированы по фактам ВСЕХ items фазы P4 (P4.1–P4.7, P4.9–P4.16):
  - `docs/02_Workflow/crawling-and-discovery.md` — типизированный фильтр профиля, перекрытие окон
    + суточный дозор, сиды площадки ЕИС, живые лимиты (потолок листинга **5 000**, TLS-доверие в
    образе), ссылка на новый runbook.
  - `docs/03_Dev/crawling-pipeline.md` — те же факты техническим языком: `EisSearchFilter`/
    `CrawlerFilterFactory`, `DeltaScanProcessor`/`window_overlap_days`, дедуп `CsvBlockIngestor`,
    метрика `scan_gap_seconds`/`oldest_update_seen`, сиды, калибровка констант, `SourceTlsTrust`,
    фактическая раскладка HTML карточки (со ссылкой на `findings/P4-card-layout.md` и датой съёма
    2026-08-05), фикс транспорта Д-8 + оба сторожа тихого отказа.
  - `docs/specs/v2/02-crawling.md` — правка ТОЛЬКО ошибочного факта (потолок «около 10 000» →
    «ровно 5 000», §3.3 и §9, с датой ре-верификации); остальной текст спеки не тронут.
  - Новый `docs/03_Dev/runbooks/archive-run.md` (Symptoms/Triage/Fix/Rollback/Escalation) +
    строка в `docs/03_Dev/runbooks/_index.md`.
  - Термины в `docs/02_Workflow/glossary.md` и `.ai/guidelines/glossary.md`; один абзац вручную
    перенесён в `CLAUDE.md` (Domain Hub); `.claude/skills/procurement-pipeline-dev/SKILL.md`
    (без маркера `swissknifeman:managed`) дополнен §6.
  - `.ai/guidelines/architecture.md` не менялся — фаза не меняла доменную раскладку слоёв.
- **Приёмочный прогон фазы:** `grep -rn "KeyValue::make('filter')" app/` — пусто;
  `phpstan analyse --memory-limit=1G` — `[OK] No errors`, baseline **56** записей (не изменился);
  `pint --dirty --test` — зелёный; полный сюит `php artisan test --no-tia` — **10 failed,
  1 skipped, 1071 passed**, состав падений байт-в-байт тот же, что закрытие P4.16
  (`CoreKnowsNoDocumentTypeTest`×2, `CostReportTest`, `PromptApplyTest`×2, `GeminiProxyRoutingTest`,
  `TextGridRendererTest`, `SupplierResultsProcessorTest`×3 — `pg_partman`, вход **P7.4**) — новых
  падений от диффа item'а **0**.
- **Фаза P4 терминальна**: `plan.md` §4 Phase Index — `6/16`, `🟠 Done with deviations`.
- Синхронизированы `plan.md` (§4 Phase Index, §6 Update Log), `phases/P4.md` (Phase Status, item
  P4.8 Status/Completion Notes/Pending Work, write-site), `journal.jsonl`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна (все 16 items закрыты: P4.1/P4.2/P4.8/
  P4.10/P4.11/P4.12 🟢; P4.3/P4.4/P4.5/P4.6/P4.7/P4.9/P4.13/P4.14/P4.15/P4.16 🟠).
- **P7 0/8** ⬜ Not started — следующая фаза очереди D63.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63) исчерпан для P1–P6/P4** — остаётся только **P7** (последняя фаза очереди),
  затем детализация и исполнение **P8** (сегодня скелет).
- **P7 — 8 items, поitem'ный Routing**: P7.1 (frontier/high, manual, критерий severity) → P7.2
  (implementation/high, manual) → P7.3 (frontier/high, manual, модель БД D72) → P7.4
  (implementation/high, manual, `pg_partman`/`pg_stat_statements` — тот самый вход, что блокирует
  `markDetailFetched` реальных закупок, найдено P4.16) → P7.5 (implementation/high, manual,
  миграции) → P7.6 (frontier/high, manual) → P7.7 (implementation/high, manual) → P7.8
  (implementation/low, plan-exec, приёмка фазы).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **`prc_events`/pg_partman не покрывает исторические даты** (найдено P4.16) — блокирует
  `markDetailFetched` на реальных закупках с историческими событиями; вход **P7.4**, приоритет
  выше, чем «источник флейка сюита» — продовый блокер.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца, вход готов:
  P4.16 предъявила живые числа документного контура; вне Scope плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** — дефект скрипта-сэмплера; пересдать нечем до следующего
  боевого прогона.

**Sources of truth:**

- `plan.md` §4 Phase Index & Status Board (P4 терминальна, 6/16) · §6 Update Log (строка P4.8).
- `phases/P4.md` item **P4.8** Completion Notes — что и куда синхронизировано, полный список
  Validation.
- `phases/P7.md` — 8 items до DoR, начиная с **P7.1**.
- `docs/03_Dev/crawling-pipeline.md`, `docs/02_Workflow/crawling-and-discovery.md`,
  `docs/03_Dev/runbooks/archive-run.md` — канон фазы P4 теперь здесь, не в `plans/…`.

**Open risks:**

- **`prc_events`/pg_partman не покрывает исторические даты** — см. Remaining; вход P7.4,
  эксплуатационный блокер повторных ре-фетчей детального слоя на реальных закупках.
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16) —
  организационный блок отсутствует в текущей вёрстке `common-info`; роли/организации нужно искать
  в другом источнике, если понадобятся P5/P8.
- **`agelto_horizon` поднят** — после появления документов исполнит `PrepareDocumentJob`;
  AI-стадии за выключенными гейтами (подтверждено: `prc_ai_analysis_runs`/`llm_calls` не выросли).
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Оценка корпуса для P7.3 — НИЖНЯЯ граница**: 2.61 КБ на закупку до P4.16; после появления
  документов/деталей вырастет — пересчёт входит в P7.3.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — файлы разные, отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — рантайм-выравнивание `agelto_minio` (P4.16) не
  персистентно в `.env`; следующий `docker compose up`/`build` без переменной пересоздаст
  контейнер с требованием заново задать пароль. **PHPStan в контейнере падает на дефолтном 256M**
  (обход `--memory-limit=1G`). **`docker compose` CLI требует `MINIO_ROOT_PASSWORD` для
  интерполяции** даже при обращении к уже запущенным контейнерам — обходится `docker exec
  <container>` напрямую, минуя `docker compose exec`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app`/`docker exec agelto_postgres` вместо `docker compose
  exec` (интерполяция `docker compose` падает без `MINIO_ROOT_PASSWORD` в окружении хоста);
  `MINIO_ROOT_PASSWORD=<любое> docker compose build/up` (P4.16); `phpstan analyse
  --memory-limit=1G`; `agelto_scheduler` остановлен; `Registry::getAttribute('code')` вместо
  `->code` в новом коде; `vacuum full` после массового удаления.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126); серия M-5;
  персистентность корня для ОСТАЛЬНЫХ каналов площадок; два типа процедуры вне
  `procedure_url_types`; стартовый ценовой стек от распределения; детализация фазы P8 и повторный
  `finish`; `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-4 (ловушка стартового стека
  `0:0`); детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
  `config('crawler.filters')`; докблок `@property string $code` у `Registry`; переименование
  `crawler.archive.date_format` → `crawler.eis.date_format`; писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls` (см. Open risks); `RerunRangeAction`
  `replaceResources()`; научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку
  `work_type_id`; персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф
  `pg_partman`, `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба
  мёртвого диска `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; историческая витрина числа
  дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)»; правка формы
  `FinancingRowData::fiscalYear`; явный пояс МСК в `EisCsvRowMapper` под К-6; поиск источника
  ролей/организаций закупки, если понадобятся P5/P8 (`prc_procurement_parties` подтверждённо пуст).
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
