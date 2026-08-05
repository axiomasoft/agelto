# HANDOFF — 2026-08-05 — after P7.1

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — **P7.2** (метрики нагрузки: сверка с закрытым
списком критериев §1, досъём постфактум из каталога, вердикт измеримости по каждому критерию).
Фаза P7 — последняя в очереди D63, 1/8 закрыт.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано §3 Routing (`implementation/high`), инварианты «не реконструировать неснятое» |
| Context | continue (/clear) — ручной item |
| Суть | Сверить `findings/P4-load-baseline.md` с критериями `rag` §1, досняться из каталога, вынести «измерено / не измерено, потому что …» по каждой строке |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P7.2
```

**Сделано этим заходом (`plan-run P7.1`, opus/xhigh, 2026-08-05).**

- **P7.1 закрыт 🟢 Done**, item-коммит (завершающий) **`9481d85`** (первая редакция — `b934595`),
  три файла из `Files`: `findings/P7-schema-inventory.md` (слой 1 — факты, запросы, время снятия),
  `research/08-db-review.md` (слой 2 — вердикты), `artifacts/p7-schema-findings.json`.
- **Ревизия проведена на КАТАЛОГЕ живой БД** `agelto` (боевые данные P4.7 + P4.15 + P4.16), окно
  2026-08-05 14:27–14:36 UTC, tree-якорь чисел `6ddaa62`. Чеклист `rag` §8 пройден в фиксированном
  порядке P0 → P2 с результатом по КАЖДОМУ из семи пунктов.
- **13 находок, 5 наблюдений, 6 строк на гейт владельца.** По D60: **P0 — 5** (25 FK без
  покрывающего индекса; 28 связей на дефолтном `NO ACTION` при нуле `RESTRICT` и расколе правил у
  детей `prc_procurements`; `prc_items.quantity numeric(15,3)` против `numeric(18,6)`; два
  непостроенных FK — `prc_revisions.crawler_run_id`, `llm_calls.analysis_run_id`); **P1 — 2**
  (нет ни одной DEFAULT-партиции при покрытии `[2026-08-01, 2026-11-01)`; **обслуживание партиций
  не отрабатывает вовсе**); **P2 — 3** (13 колонок `prc_procurements` пусты на 100 % корпуса;
  предикаты по ключам `prc_ai_analyses.result` без индекса; 8 из 15 индексов `prc_procurements`
  без единого скана); **вне шкалы D60 — 3 рантайм-находки** (нет таймаутов кластера; `shm_size`
  на `horizon`, не на `postgres`; нет `pg_stat_statements`/`pgstattuple`).
- **Три пункта чеклиста дали явное «нарушений нет»**: P1.1 целиком (0 invalid-индексов), P1.2 в
  части классических ловушек (PK ⊇ ключ партиционирования 5/5, FK на партиционированную 0), P2.1 в
  части антипаттерна дефолтов (`nullable` + `DEFAULT` — 0 из 865 колонок).
- **Мандатные вердикты:** `prc_revisions` — `retention = NULL` подтверждённое решение (D74/V2-CORE
  D41), не находка, наблюдение с числом **3 партиции / 25 МБ / 17 942 строки** (≈ 1.4 КБ на
  ревизию); таблица `cache` — **оставить** до смены fallback `config/cache.php` в P7.4 (D75).
- **Состав P7.2–P7.8 (D20):** шесть подтверждены, **P7.5 изменён D132** — Scope Included расширен
  тремя строками (выражающий индекс под названный потребитель; создание партиций/DEFAULT-партиции
  по решению владельца; обобщение пруна `partman.part_config`).
- **Validation — 7 из 7 клауз passed** (числа и команды: `phases/P7.md` P7.1 Completion Notes).
- Синхронизированы `plan.md` (§4 Phase Index → `1/8`, `🟡 In progress`; §5 Decision Log — D132;
  §6 Update Log), `phases/P7.md` (Phase Status, P7.1 Status/Completion Notes/Pending Work/Known
  Deviations/write-site, Scope Included P7.5), `journal.jsonl`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P7 1/8** 🟡 In progress — P7.1 🟢; открыты P7.2–P7.8.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P7 — 7 items**: P7.2 (implementation/high, manual) → P7.3 (frontier/high, manual, модель БД
  D72) → P7.4 (implementation/high, manual, `pg_stat_statements`/`pgstattuple`/`shm_size`/кэш на
  Redis — плюс F-10/F-11/F-12 ревизии) → P7.5 (implementation/high, manual, миграции по
  утверждённому перечню — **вход блокирован двумя строками гейта владельца**) → P7.6
  (frontier/high, manual) → P7.7 (implementation/high, manual) → P7.8 (implementation/low,
  plan-exec, приёмка фазы и закрытие плана).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца; вне Scope
  плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** — дефект скрипта-сэмплера; пересдать нечем до следующего
  боевого прогона.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/findings/P7-schema-inventory.md` — слой 1 ревизии: все запросы,
  выводы и время снятия; сюда же ушли три поправки к фактам разведки §13.
- `plans/2026.07.31-SYS-INTEGRITY/research/08-db-review.md` — слой 2: 13 находок с адресом,
  последствием, severity и классом правки; §12 — гейт владельца; §13 — сводка; §15 — состав items.
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/p7-schema-findings.json` — машинный слепок того же.
- `plan.md` §4 Phase Index (P7 `1/8`) · §5 D132 · §6 Update Log (строка P7.1).
- `phases/P7.md` P7.1 Completion Notes — полный прогон Validation и мандатные вердикты.

**Open risks:**

- **ГЕЙТ ВЛАДЕЛЬЦА — 6 вопросов, два из них блокируют состав миграций P7.5** (`research/08` §12):
  (1) правило `ON DELETE` для пяти «аудитных» детей `prc_procurements` — `CASCADE` или `RESTRICT`;
  (2) чем закрывать выход за диапазон партиций — DEFAULT-партицией или предсозданием исторических;
  (3) судьба 13 пустых колонок `prc_procurements`; (4) судьба 8 индексов без единого скана;
  (5) индексы под FK в таблицах пакета azguard; (6) удаление таблиц `cache`/`cache_locks` после
  P7.4. Ответы нужны ДО P7.5, но P7.2/P7.3/P7.4/P7.6 ими не блокируются.
- **Обслуживание партиций мертво (F-13, P1)** — `partman.run_maintenance()` падает на трёх
  осиротевших строках `part_config`; premake не работает, retention-DROP не исполняется никогда.
  **Партиции кончаются `2026-11-01`**, после чего запись во все пять партиционированных таблиц
  падает целиком, включая `prc_fetches`/`prc_events`/`prc_revisions` боевого кроулинга. Адресаты —
  P7.5 (обобщение пруна) и P7.4 (сторож видимости отказа).
- **`prc_events` не покрывает исторические даты** (найдено P4.16, подтверждено ревизией как F-06) —
  блокирует `markDetailFetched` на реальных закупках с историческими событиями; складывается с
  F-13 (сетки нет И механизм её расширения сломан).
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16).
- **`agelto_horizon` поднят**; AI-стадии за выключенными гейтами. **`agelto_scheduler` остановлен**
  — то есть `partitions:maintain` сейчас не запускается даже неуспешно.
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Оценка корпуса для P7.3 — НИЖНЯЯ граница**: ревизия даёт свежие числа (`prc_procurements`
  18 МБ / 17 943, `prc_revisions` 25 МБ / 17 942, `prc_events` 3168 кБ / 18 004) — пересчёт входит
  в P7.3.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` CLI требует её для интерполяции даже
  при обращении к уже запущенным контейнерам; обходится `docker exec <container>` напрямую (этим
  заходом снята вся фактура БД). **PHPStan в контейнере падает на дефолтном 256M** (обход
  `--memory-limit=1G`).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app`/`docker exec agelto_postgres` вместо `docker compose
  exec`; `MINIO_ROOT_PASSWORD=<любое> docker compose build/up` (P4.16); `phpstan analyse
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
  `work_type_id`; персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; `memory_limit`-конфликт
  `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска `estimates_s3`; колонка
  диска для `prc_ai_dataset_examples.gold_ref`; перевод `FormatBenchRunner`/`GoldBuildCommand`/
  `FixtureForm`/`FixturesVerifyCommand` на `FileLocationResolver`; реальное удаление объектов на
  исходном диске после окна отката; операторская правка `options.records_per_page` через форму
  профиля; историческая витрина числа дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)»;
  правка формы `FinancingRowData::fiscalYear`; явный пояс МСК в `EisCsvRowMapper` под К-6; поиск
  источника ролей/организаций закупки, если понадобятся P5/P8.
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
