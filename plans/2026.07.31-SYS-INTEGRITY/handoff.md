# HANDOFF — 2026-08-05 — after P7.7

**Next:** `/task:plan-exec 2026.07.31-SYS-INTEGRITY P7.8` — финальный приёмочный прогон фазы P7
и синхронизация доков по уже принятым решениям (Routing: `implementation/low`, `Exec = plan-exec`,
`Review = light`, solo — Inputs ждут Completion Notes P7.1–P7.7, все закрыты).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — предписано §3 Routing (`implementation/low`), приёмка по уже принятым решениям, новых решений нет |
| Context | continue (/clear) — ручной item |
| Суть | Приёмочный прогон фазы P7 (все Validation-клаузы P7.1–P7.7) + синхронизация доков под фактическое поведение |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P7.8
```

**Сделано этим заходом (`plan-run P7.7`, sonnet/high, 2026-08-05).**

- **Гейт OQ-9 разрешён вопросом в сессии перед началом мутаций (D134):** позиции 1–5
  (`research/10-process-audit.md` §6.1) включены в Scope P7.7 без изменений; позиции 6–8
  (Д-6/Д-7/Д-8 — заведение отсутствующих процессов расписания) исключены, уходят в отдельный план
  (та же корзина, что и восемь пунктов §6.3, D59).
- **P7.7 закрыт 🟠 Done with deviations**, item-коммит **`245c58d`** — девять файлов: два
  `app/Console/Commands/Procurement/*.php`, `config/horizon.php`, `docker-compose.yml`,
  `docs/03_Dev/document-pipeline.md`, четыре тестовых файла (два новых). Все пять фиксов
  утверждённого перечня выполнены:
  1. **Д-4** — `pipeline:retry-failed` больше не глушит extraction безусловным
     `LogicException`: резолвит `DocumentAnalyzer` через `DocumentAnalyzerRegistry` (тот же приём,
     что `RerunAnalysisAction`, D93) и зовёт `dispatchExtraction()`.
  2. **Д-1** — `supervisor-ai-shadow` заведён в `config/horizon.php` (потолок
     `supervisor-ai-extraction`: `timeout=300`/`memory=256`, `maxProcesses=1` без env-override).
  3. **Д-5** — `pipeline:stuck-check::stuckDocuments()` включает `DocumentStatus::Discovered`.
  4. **Д-2+Д-3 (D135, новая D-запись)** — `tries: 1` названо решением явно (комментарий в
     `config/horizon.php` + D135); сторож `tests/Unit/Architecture/JobRetryContractGuardTest.php`
     запрещает свойства ретрая на job'ах Procurement и подмену
     `ShouldBeUniqueUntilProcessing` → `ShouldBeUnique` на `EstimateExtractionJob` (D39).
  5. **Д-9** — `stop_grace_period: 600s` у `horizon` в dev `docker-compose.yml` (инвариант D26).
- **Каждый новый/изменённый тест доказан красным ДО фикса** (`git stash`/точечная временная правка
  + `git checkout --`) и зелёным после — Implementation Rule 5. Новый тест на уникальность при
  конкурентном диспатче — `EstimateExtractionJobTest.php` (3 теста); checkpoint-requeue (D39) и
  идемпотентность повторного запуска УЖЕ были покрыты `ExtractionOrchestratorTest.php` — новых
  тестов на них не заводилось.
- **Validation:** полный сюит — 1088 passed / 6 failed (ВСЕ предсуществующие, подтверждено `git
  stash`) / 1 skipped (якорь `245c58d`); тестов 182 (было 180, +2 новых файла); PHPStan — No
  errors; Pint (хост) — passed; grep-доказательство решения по `ai_shadow` — в Completion Notes.
- **Known Deviations — Files-разрыв:** `Files` item'а не перечислял
  `app/Console/Commands/Procurement/{PipelineRetryFailed,PipelineStuckCheck}.php`,
  `docker-compose.yml` и `tests/Unit/Architecture/**` — все четыре пути были физически необходимы
  для утверждённого владельцем перечня (D134), Scope Included называл их фиксы прямо. Дефект
  Files-спеки item'а (написана до вердикта OQ-9), не самоуправство исполнителя — status-rule
  `plan-exec.md` предписывает 🟠 + запись для этого случая, не эскалацию.
- **Known Deviations — 6 предсуществующих failing-тестов**, не от диффа P7.7 (подтверждено `git
  stash`): `CoreKnowsNoDocumentTypeTest` (2, канарейка периметра не подновлена), `PromptApplyTest`
  (2, `Permission denied` на запись промтов — файловые права контейнера), `GeminiProxyRoutingTest`
  (1, Faker-пароль не через `rawurlencode()` в самом тесте), `TextGridRendererTest` (1,
  `memory_limit` при накопленной памяти полного прогона). Ни один не блокирует P7.7 — фикс-предложение
  по каждому в Known Deviations `phases/P7.md`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P7 4/8** 🟡 In progress — P7.1/P7.2/P7.3/P7.6 🟢, P7.4/P7.5/P7.7 🟠; открыт P7.8.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P7 — 1 item**: P7.8 (implementation/low, plan-exec, приёмка фазы и закрытие плана).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Объём, вынесенный P7.6 в отдельный план** (`research/10` §6.3, основание D59): Д-10
  (наложение расписанных crawler-команд — нужен дизайн claim'а), Д-11 (`routes/console.php`
  ходит в БД на каждый artisan-вызов), Д-12 (`Http::preventStrayRequests()`), тесты
  `procurement:convert-xlsx-to-pdf` и `crawler:run-delta`, очередная семантика job'ов через
  реальную очередь, тесты расписания (сегодня 0), развязка ретрая скачивания от
  refetch-расписания закупки, перенос стадии скачивания в job. **Плюс объём P7.6 §6.2 позиции
  6–8** (Д-6/Д-7/Д-8 — исключены D134 из P7.7): `horizon:snapshot` не расписан, `pulse:check`/
  `pulse:trim` не расписаны, `queue:prune-failed` не расписан + видимость `failed_jobs`.
- **Files-спека item'ов, писавшихся до гейта владельца** (P7.7 Known Deviations) — риск
  повторяется у любого item'а, чей Scope зависит от research-item'а той же фазы: `plan-design`
  при следующем проходе сверяет `Files` с фактическим Scope после разрешения гейта.
- **6 предсуществующих failing-тестов** (P7.7 Known Deviations, `phases/P7.md`) — вне Scope P7,
  фикс-предложение по каждому названо, ни один не блокирует P7.8.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца; вне Scope
  плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** на контрольном прогоне P4.15 — дефект скрипта-сэмплера;
  пересдать нечем до следующего боевого прогона.
- **F-13 «сторож видимости отказа `run_maintenance()`»** (вторая половина, `research/08` §10.3) —
  плановый разрыв между P7.1 и текстом P7.4, не входил в Scope P7.5; ждёт D# от `plan-design`.
- **Четыре строки гейта владельца остаются открытыми** (`research/08-db-review.md` §12): F-07
  (13 пустых колонок `prc_procurements`), F-09 (8 неиспользуемых индексов), F-01b (индексы в
  таблицах пакета azguard), cache-гейт (удаление `cache`/`cache_locks`).
- **`agelto_postgres_pre_p74`** — стоп-контейнер, требует `docker rm` владельцем вне харнесса
  (см. Known Deviations P7.4).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/10-process-audit.md` — §1 критерий изолируемости,
  §2 таблицы всех 52 процессов с вердиктами и ссылками на тесты, §3 находки Д-1…Д-12 с
  доказательствами, §4 наблюдаемость отказа, §5 сводка со счётчиками, §6 перечень для гейта.
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/p7-process-audit.json` — машинная форма того же;
  карта процесс→тест построена грепом инвокации, а не руками.
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` **OQ-9** — Closed (D134).
- `plans/2026.07.31-SYS-INTEGRITY/phases/P7.md` P7.7 Completion Notes — полный текст Validation,
  провенанс каждого числа и falsifiability-доказательство каждого нового теста.
- `plan.md` §4 Phase Index (P7 `4/8`) · §5 Decision Log (D134/D135) · §6 Update Log (строки
  P7.1–P7.7).

**Open risks:**

- **Наблюдаемость сама не обслуживается (Д-6/Д-7/Д-8, исключены D134 из P7.7)**: снапшоты
  Horizon не снимаются, `pulse_*` не прунятся (4198 строк / 2952 kB на 2026-08-05), `failed_jobs`
  (19 записей) не читается и не чистится — объём уходит в отдельный план.
- **Очередь `ai_shadow` теперь имеет supervisor, но контур ещё не включён** ни на одном
  маршруте (`llm_routes.shadow=true` не заведено) — фикс Д-1 профилактический, не проверен живым
  трафиком.
- **Обслуживание партиций (F-13, P1) — ЧАСТИЧНО адресовано.** Прун `partman.part_config`
  обобщён (P7.5); видимость отказа `run_maintenance()` не реализована нигде. **Партиции
  кончаются `2026-11-01`** (`premake=3`; исторические партиции P7.5 горизонт не меняют).
- **`org_organizations` живёт по UPDATE-модели, будучи продуктовой таблицей** (найдено P7.3) —
  решение владельца по OQ-5, вне Scope этого плана.
- **Подтверждение D71 остаётся частичным** до первого боевого извлечения смет.
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16).
- **`agelto_horizon` поднят**; AI-стадии за выключенными гейтами. **`agelto_scheduler` остановлен**
  — `partitions:maintain` не запускается даже неуспешно.
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и `research/10-process-audit.md`
  (P7.6) делят номер `10` — отмечено в `index.md`; переименование вне Scope P7.6.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не читается харнессом вовсе** — `docker compose`/`docker compose exec` требуют
  `MINIO_ROOT_PASSWORD` в shell-окружении инвокации (в этой сессии — снят из env уже работающего
  `agelto_minio`). **PHPStan в контейнере падает на дефолтном 256M** (обход `--memory-limit=1G`).
  **`psql` внутри `agelto_postgres` требует `-U agelto`**.
- **`docker compose {up,restart,down} postgres` сломан до ручного `docker rm
  agelto_postgres_pre_p74`** владельцем — до этого управлять контейнером напрямую через `docker`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** снятие секретов из env уже работающих контейнеров вместо чтения `.env`;
  управление `postgres` через raw `docker` вместо `docker compose` до ручного `docker rm
  agelto_postgres_pre_p74`; на партиционированных referencing-таблицах FK добавляется без
  `NOT VALID` (Postgres 17 не поддерживает); falsifiability-доказательство красноты новых тестов —
  через `git stash`/точечную временную правку с `git checkout --`, а не постоянный revert-коммит.
- **Deferred:** положения (3), (4), (5) модели D72 — OQ-5, отдельный план. F-13 «сторож
  видимости отказа `run_maintenance()`» — ждёт D# от `plan-design`. Четыре строки гейта
  владельца (F-07, F-09, F-01b, cache) — вне Scope P7.5. Восемь пунктов §6.3 `research/10` +
  три пункта §6.2 позиции 6–8 (Д-6/Д-7/Д-8) — объём процессного покрытия, вынесенный из P7.6/P7.7
  в отдельный план (D59/D134). 6 предсуществующих failing-тестов — фикс-предложение в Known
  Deviations `phases/P7.md` P7.7, ждёт отдельного fix-item'а.
- **Open questions:** OQ-3 (источник истины ЕИС), OQ-5 (продуктовый контур), OQ-6 (типизированный
  носитель семантики кейсов enum).
