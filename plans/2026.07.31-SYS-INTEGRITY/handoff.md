# HANDOFF — 2026-08-05 — after P7.4

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — **P7.5** (неразрушающие миграции по подтверждённым
находкам ревизии). **Блокер входа**: два из шести вопросов гейта владельца (`research/08-db-review.md`
§12 — F-06 диапазон партиций, F-02-гейт `ON DELETE`) держат состав миграций P7.5; без ответа
владельца item не запускается по своему собственному Inputs/Scope.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано §3 Routing (`implementation/high`), миграции на боевых данных, класс «migrations» всегда `full` |
| Context | continue (/clear) — ручной item, но см. блокер выше |
| Суть | Миграции по перечню находок `research/08-db-review.md` (класс «неразрушающая»): индексы под FK, недостающие FK/`ON DELETE`, точность `numeric`, `NOT NULL` с backfill, обобщение пруна `partman.part_config` (F-13) — ПОСЛЕ ответа владельца на F-06/F-02-гейт |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P7.5
```

**Перед запуском P7.5 — вопрос владельцу (блокирует состав миграций, не техника):** правило
`ON DELETE` для пяти «аудитных» детей `prc_procurements` (CASCADE/RESTRICT, F-02-гейт) и чем
закрывать выход за диапазон партиций — DEFAULT-партицией или предсозданием исторических
(F-06). `research/08-db-review.md` §12 несёт все шесть строк гейта (два блокируют P7.5, четыре —
нет).

**Сделано этим заходом (`plan-run P7.4`, sonnet/high, 2026-08-05).**

- **P7.4 закрыт 🟠 Done with deviations**, item-коммит **`a73d3b8`** — ровно `Files`:
  `docker-compose.yml`, `docker-compose.prod.yml`, `config/cache.php`,
  `database/migrations/2026_08_05_100068_enable_db_observability_extensions.php`,
  `tests/Feature/Infra/DbObservabilityTest.php`, `docs/03_Dev/database.md`.
- **Наблюдаемость (F-12).** `shared_preload_libraries=pg_stat_statements` + `CREATE EXTENSION
  pg_stat_statements`/`pgstattuple` (dev и prod идентично). После рестарта: `pg_stat_statements`
  отвечает (510 строк), оба расширения в `pg_extension` (`agelto` и `agelto_test`). Гейт-тест
  `DbObservabilityTest` — доказана краснота без расширений, зелёный с ними.
- **Параметры кластера (F-10).** `statement_timeout=600000` (10 мин), `idle_in_transaction_session_timeout=300000`
  (5 мин — по самому длинному supervisor-таймауту Horizon), `log_min_duration_statement=1000`
  (1 с) — RAG-обоснованы (`last9.io` + `postgresql.org/docs/current/runtime-config-client.html`).
- **`shm_size` (F-11).** `512mb` на сервисе `postgres` (dev+prod); блок `horizon` (`2gb`, prod)
  сохранён явно — резерв под будущую браузерную автоматизацию (CLAUDE.md, Фаза 8), не перенос.
- **Кэш (D73/D75).** `config/cache.php` fallback → `redis`; эффективно `redis` в `app` и
  `horizon`; таблица `cache` не растёт (0 до/после); `.env.example`/`.env` не пиннят `database` —
  owner-gate строка не нужна; посторонний диф `.env.example` (`ADMIN_EMAIL`) не тронут.
- **Полный сюит — 1072 passed, 10 failed, 1 skipped** (якорь `a73d3b8`) — все 10 вне `Files`
  item'а: 2 архитектурных (счётчик периметра ядра 98→101, drift), 3 `SupplierResultsProcessorTest`
  (подтверждённый F-06/P4.16 — `prc_events` не покрывает июль 2026), 5 Ai/Llm/Prepare-домен.
  Ни один не связан с GUC/`shm`/кэшем. `pint --dirty --test` — чисто.
- **Известный разрыв: F-13 «сторож видимости отказа `run_maintenance()`» назван адресатом P7.4 в
  `research/08-db-review.md` §10.3/§13 и Pending Work P7.1, но отсутствует в собственных
  Intent/Scope Included/Files/Deliverables P7.4** — не реализован (дисциплина Scope, `plan-exec`
  правило 4). Нужен D# от `plan-design`.
- **Known Deviations (process).** Рестарт кластера не прошёл штатным `docker compose up -d
  postgres` — 4 попытки падали на баге демона (`Network … Removed` → `active endpoints` пяти
  других сервисов), с побочным разрывом network-alias `postgres` (устранялось между попытками,
  простоя данных для приложения не было). Устранено вручную: старый контейнер переименован
  (`agelto_postgres_pre_p74`), новый `agelto_postgres` поднят `docker run` с параметрами,
  побайтово снятыми с рабочего контейнера + новыми `command`/`--shm-size`. **Хвост**:
  `agelto_postgres_pre_p74` (остановлен, отдельный volume, данные не трогает) ломает будущую
  `docker compose`-реконсиляцию сервиса `postgres` (compose матчит по меткам, не по имени) —
  `docker rm agelto_postgres_pre_p74` заблокирован харнессом (`deny_hard`), нужно РУЧНОЕ действие
  владельца вне сессии; до этого — `postgres` только через `docker`, не `docker compose`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P7 3/8** 🟡 In progress — P7.1/P7.2/P7.3 🟢, P7.4 🟠; открыты P7.5–P7.8.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P7 — 4 items**: P7.5 (implementation/high, manual, миграции — **вход блокирован двумя
  строками гейта владельца**) → P7.6 (frontier/high, manual) → P7.7 (implementation/high, manual)
  → P7.8 (implementation/low, plan-exec, приёмка фазы и закрытие плана).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца; вне Scope
  плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** на контрольном прогоне P4.15 — дефект скрипта-сэмплера;
  пересдать нечем до следующего боевого прогона.
- **F-13 «сторож видимости отказа `run_maintenance()`»** — плановый разрыв между P7.1/`research/08`
  и текстом P7.4 (см. выше); ждёт D# от `plan-design`.
- **`agelto_postgres_pre_p74`** — стоп-контейнер, требует `docker rm` владельцем вне харнесса
  (см. Known Deviations P7.4).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/08-db-review.md` §11 (F-10/F-11/F-12, вход P7.4) · §10.3
  (F-13, оба адресата) · §12 (гейт владельца, вход P7.5) · §13 (сводка).
- `docs/03_Dev/database.md` — раздел «Наблюдаемость и рантайм-параметры кластера (P7.4)» — канон
  порядка рестарта, точки отката, что включено.
- `docker-compose.yml`/`docker-compose.prod.yml` — `postgres.command`/`shm_size`; `config/cache.php:20`.
- `plans/2026.07.31-SYS-INTEGRITY/findings/rag-2026-07-31.md` §7 — методика снятия метрик,
  что требует рестарта.
- `plan.md` §4 Phase Index (P7 `3/8`, численно без изменений — P7.4 закрыт 🟠, не 🟢) · §6 Update
  Log (строки P7.1–P7.4).

**Open risks:**

- **ГЕЙТ ВЛАДЕЛЬЦА — 6 вопросов, два из них блокируют состав миграций P7.5** (`research/08` §12):
  (1) правило `ON DELETE` для пяти «аудитных» детей `prc_procurements` — `CASCADE` или `RESTRICT`;
  (2) чем закрывать выход за диапазон партиций — DEFAULT-партицией или предсозданием исторических;
  (3) судьба 13 пустых колонок `prc_procurements`; (4) судьба 8 индексов без единого скана;
  (5) индексы под FK в таблицах пакета azguard; (6) удаление таблиц `cache`/`cache_locks` —
  **теперь решаемо**: fallback переведён на `redis`, счётчик подтверждён не растущим (P7.4).
  Ответы нужны ДО P7.5, но P7.6/P7.7 ими не блокируются.
- **Обслуживание партиций мертво (F-13, P1) — ЧАСТИЧНО адресовано.** Причина отказа
  `run_maintenance()` не устранена P7.4 (это Scope P7.5 — обобщение пруна, D132); видимость
  отказа (вторая половина F-13) не реализована нигде — плановый разрыв, см. Pending Work P7.4.
  **Партиции кончаются `2026-11-01`.**
- **`org_organizations` живёт по UPDATE-модели, будучи продуктовой таблицей** (найдено P7.3) —
  решение владельца по OQ-5, вне Scope этого плана.
- **Подтверждение D71 остаётся частичным** до первого боевого извлечения смет — не адресовано
  P7.4 (вне его Scope; инструментовка введена, но новых боевых чисел не даёт).
- **`prc_events` не покрывает исторические даты** (F-06/P4.16) — теперь ВИДНО как красный тест
  (`SupplierResultsProcessorTest`, 3 кейса) полным сюитом; сам дефект не чинится этим планом
  (Scope P4/P5 закрыты, P7.5 его не несёт).
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16).
- **`agelto_horizon` поднят**; AI-стадии за выключенными гейтами. **`agelto_scheduler` остановлен**
  — `partitions:maintain` не запускается даже неуспешно.
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**
  (подтверждено повторно P7.4 — `docker rm`), **незакоммиченный чужой дифф** (`.env.example`,
  `.swissknife.json`, `.swissknife/`, `.swissknifeman/`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — состав снимать `git status --porcelain`
  перед каждым item-коммитом.
- **`.env` не читается харнессом вовсе** (не только `MINIO_ROOT_PASSWORD` — permission denial на
  `Read`/`Bash` по пути `.env*`) — `docker compose`/`docker compose exec` требуют переменную в
  shell-окружении инвокации; значения снимать `docker exec <контейнер> printenv <VAR>` с уже
  работающего контейнера, не читать файл. **PHPStan в контейнере падает на дефолтном 256M**
  (обход `--memory-limit=1G`). **`psql` внутри `agelto_postgres` требует `-U agelto`**.
- **`docker compose {up,restart,down} postgres` сломан до ручного `docker rm
  agelto_postgres_pre_p74`** владельцем (см. Known Deviations P7.4) — до этого управлять
  контейнером `postgres` напрямую через `docker`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** снятие `MINIO_ROOT_PASSWORD`/прочих секретов из env уже работающих контейнеров
  (`docker exec <container> printenv <VAR>`) вместо чтения `.env` — харнесс блокирует прямое
  чтение файла; управление `postgres` через raw `docker` вместо `docker compose` до ручного
  `docker rm agelto_postgres_pre_p74`.
- **Deferred:** положения (3), (4), (5) модели D72 (неизменяемый после верификации продукт, схемы
  `pub`/`raw`, контур выдачи с кэшем/курсорной пагинацией/rate limit) — OQ-5, основание
  отдельного плана. F-13 «сторож видимости отказа `run_maintenance()`» — ждёт D# от `plan-design`.
- **Open questions:** OQ-3 (источник истины ЕИС — CSV или XML/SOAP), OQ-5 (продуктовый контур),
  OQ-6 (типизированный носитель семантики кейсов enum).
