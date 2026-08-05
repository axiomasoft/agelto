# HANDOFF — 2026-08-05 — after P7.2

**Next:** «ЗАПУСК ВРУЧНУЮ: frontier/high» — **P7.3** (подтверждение решения D71 «одна БД» числами
боевого прогона + фиксация целевой модели хранения D72 как канона проекта + проверяемое условие
пересмотра). Фаза P7 — последняя в очереди D63, 2/8 закрыто.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — предписано §3 Routing (`frontier/high`), открытое design-решение под гейтом владельца |
| Context | continue (/clear) — ручной item |
| Суть | Свести прирост БД на закупку (2.61 КБ, `findings/P4-load-baseline.md` §9.7) и вердикты `findings/P7-db-metrics.md` с порогами `rag-2026-07-31.md` §1, подтвердить D71 числами, зафиксировать модель D72 как канон |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P7.3
```

**Сделано этим заходом (`plan-run P7.2`, sonnet/high, 2026-08-05).**

- **P7.2 закрыт 🟢 Done**, item-коммит **`a2988a9`**, два файла из `Files`:
  `findings/P7-db-metrics.md` (сверка пяти критериев §1 с измеренным) и
  `artifacts/p7-db-metrics.json` (машинный слепок).
- **Постфактум-досъём** снят одним прогоном 2026-08-05 15:05:16 UTC, tree-якорь `ae45f4c`: dead
  tuples/autovacuum, размеры таблиц/индексов, `idx_scan`+`stats_reset`, число партиций на пяти
  партиционированных таблицах (по 3 каждая, окно `[2026-08-01, 2026-11-01)`, DEFAULT-партиции нет).
- **Вердикт по пяти критериям §1:** WAL pressure измерим (снят дважды — 14.3 МБ/мин на P4.7 против
  5.06 МБ/мин на P4.15, разница объяснена дублями Д-1); доля мёртвых строк измерима постоянно
  (4.5 % → 4.1 % → 4.16 % на трёх снятиях). **Три пункта структурно неизмеримы**: replica lag
  (реплики нет), per-query write pressure/contention (`pg_stat_statements` отсутствует, F-12,
  адресат P7.4), независимое масштабирование чтения (read-serving слоя в системе не существует).
  Contention по wait events частично: полная серия на P4.7 (60/60 сэмплов), на P4.15 — только 3
  точечных (дефект скрипта-сэмплера) — во всех случаях только `Client/ClientRead`, ни одного
  сигнала contention с БД. Строка про разные индексы/retention для write/read измерена постфактум,
  но сравнивать не с чем — read-контура в системе нет.
- **Validation — 4 из 4 клауз passed**: все пять строк §1 со значением/причиной, replica lag и
  per-query contention названы структурно, каждое число несёт команду и время, JSON валиден,
  текст не несёт вывода о выборе топологии — детали `phases/P7.md` P7.2 Completion Notes.
- Синхронизированы `plan.md` (§4 Phase Index → `2/8`; §6 Update Log), `phases/P7.md` (Phase Status,
  P7.2 Status/Completion Notes/Pending Work/write-site), `journal.jsonl`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P7 2/8** 🟡 In progress — P7.1/P7.2 🟢; открыты P7.3–P7.8.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P7 — 6 items**: P7.3 (frontier/high, manual, модель БД D72) → P7.4 (implementation/high,
  manual, `pg_stat_statements`/`pgstattuple`/`shm_size`/кэш на Redis — плюс F-10/F-11/F-12
  ревизии) → P7.5 (implementation/high, manual, миграции по утверждённому перечню — **вход
  блокирован двумя строками гейта владельца**) → P7.6 (frontier/high, manual) → P7.7
  (implementation/high, manual) → P7.8 (implementation/low, plan-exec, приёмка фазы и закрытие
  плана).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца; вне Scope
  плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** на контрольном прогоне P4.15 — дефект скрипта-сэмплера;
  пересдать нечем до следующего боевого прогона (подтверждено повторно P7.2 Pending Work).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/findings/P7-schema-inventory.md` — слой 1 ревизии схемы: все
  запросы, выводы и время снятия.
- `plans/2026.07.31-SYS-INTEGRITY/research/08-db-review.md` — слой 2: 13 находок с адресом,
  последствием, severity и классом правки; §12 — гейт владельца; §13 — сводка; §15 — состав items.
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/p7-schema-findings.json` — машинный слепок ревизии
  схемы.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P7-db-metrics.md` — сверка критериев §1 с измеренным +
  вердикт измеримости каждого (P7.2).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/p7-db-metrics.json` — машинный слепок сверки метрик.
- `plan.md` §4 Phase Index (P7 `2/8`) · §5 D132 · §6 Update Log (строки P7.1, P7.2).
- `phases/P7.md` P7.1/P7.2 Completion Notes — полные прогоны Validation и мандатные вердикты.

**Open risks:**

- **ГЕЙТ ВЛАДЕЛЬЦА — 6 вопросов, два из них блокируют состав миграций P7.5** (`research/08` §12):
  (1) правило `ON DELETE` для пяти «аудитных» детей `prc_procurements` — `CASCADE` или `RESTRICT`;
  (2) чем закрывать выход за диапазон партиций — DEFAULT-партицией или предсозданием исторических;
  (3) судьба 13 пустых колонок `prc_procurements`; (4) судьба 8 индексов без единого скана;
  (5) индексы под FK в таблицах пакета azguard; (6) удаление таблиц `cache`/`cache_locks` после
  P7.4. Ответы нужны ДО P7.5, но P7.3/P7.4/P7.6 ими не блокируются.
- **Обслуживание партиций мертво (F-13, P1)** — `partman.run_maintenance()` падает на трёх
  осиротевших строках `part_config` (`ai_llm_calls`/`crw_fetches`/`sys_operation_logs` — подтверждено
  постфактум-снятием P7.2, всё ещё в `partman.part_config`); premake не работает, retention-DROP не
  исполняется никогда. **Партиции кончаются `2026-11-01`**, после чего запись во все пять
  партиционированных таблиц падает целиком, включая `prc_fetches`/`prc_events`/`prc_revisions`
  боевого кроулинга. Адресаты — P7.5 (обобщение пруна) и P7.4 (сторож видимости отказа).
- **`prc_events` не покрывает исторические даты** (найдено P4.16, подтверждено ревизией как F-06) —
  блокирует `markDetailFetched` на реальных закупках с историческими событиями; складывается с
  F-13 (сетки нет И механизм её расширения сломан).
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16).
- **`agelto_horizon` поднят**; AI-стадии за выключенными гейтами. **`agelto_scheduler` остановлен**
  — то есть `partitions:maintain` сейчас не запускается даже неуспешно.
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Оценка корпуса для P7.3 — НИЖНЯЯ граница**: `findings/P4-load-baseline.md` §9.7 даёт
  2.61 КБ/закупку без документного и детального слоя (Д-8) — число, с которым P7.3 обязана
  работать, а не 9.2 КБ первого прогона.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` CLI требует её для интерполяции даже
  при обращении к уже запущенным контейнерам; обходится `docker exec <container>` напрямую.
  **PHPStan в контейнере падает на дефолтном 256M** (обход `--memory-limit=1G`). **`psql` внутри
  `agelto_postgres` требует `-U agelto`** (не `postgres` — роль отсутствует).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app`/`docker exec agelto_postgres` вместо `docker compose
  exec`; `docker exec agelto_postgres psql -U agelto -d agelto` (не `-U postgres`);
  `MINIO_ROOT_PASSWORD=<любое> docker compose build/up` (P4.16); `phpstan analyse
  --memory-limit=1G`; `agelto_scheduler` остановлен; `Registry::getAttribute('code')` вместо
  `->code` в новом коде; `vacuum full` после массового удаления.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126); серия M-5 (P4.15, не
  пересдана и P7.2); персистентность корня для ОСТАЛЬНЫХ каналов площадок; два типа процедуры вне
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
