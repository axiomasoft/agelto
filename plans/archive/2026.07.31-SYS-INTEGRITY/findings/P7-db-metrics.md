# P7.2 — Метрики нагрузки: сверка с критериями §1, досъём постфактум, вердикт измеримости

**Источники входа:** `findings/rag-2026-07-31.md` §1 (закрытый список критериев физического
разделения) и §7 (методика замера каждого); `findings/P4-load-baseline.md` (боевой прогон P4.7,
раздел 4 «Метрики нагрузки БД в трёх точках», M-1…M-9) и его раздел 9 (контрольный прогон P4.15,
§9.6–9.8); `findings/repo-recon-2026-07-31.md` §13.7 (рантайм БД и наблюдаемость);
`research/08-db-review.md` §11 (F-10/F-11/F-12 — рантайм кластера, вход P7.1).

**Постфактум-досъём этого item'а:** снят одним прогоном `docker exec agelto_postgres psql -U
agelto -d agelto` **2026-08-05 15:05:16 UTC**, tree-якорь **`ae45f4c`** (`git rev-parse
--short HEAD`, дерево без диффа схемы — только правки `plans/2026.07.31-SYS-INTEGRITY/**` этого
item'а и P7.1). Сырой вывод — `artifacts/p7-db-metrics.json`.

**Правило чтения таблицы (Implementation Rules item'а):** доля мёртвых строк
(`n_dead_tup/(live+dead)`) НЕ является bloat % и здесь так не называется (Rule 3); `idx_scan`
приводится только вместе со `stats_reset` и продолжительностью окна (Rule 4); ни одна строка не
несёт вывода о выборе топологии — сопоставление с порогами и решение «делить/не делить» —
предмет P7.3 (Rule 6).

## Сверка критериев §1

| Критерий §1 | Что требуется для замера | Снято на прогоне? | Значение | Команда и время | Вердикт измеримости |
|:--|:--|:--|:--|:--|:--|
| **WAL pressure + replica lag** | WAL pressure — дельта `pg_current_wal_lsn()` между двумя точками (или накопительные `pg_stat_wal`); replica lag — `pg_stat_replication`/`replay_lag` на физической read-реплике | WAL pressure — **да**, на обоих боевых прогонах; replica lag — **нет**, структурно | WAL: первый прогон (P4.7) — 423.0 МБ за 29 мин 40 с = 14.3 МБ/мин ≈ 238 КБ/с (`t0` 06:35:13 → `t2` 07:04:53, 2026-08-05); контрольный прогон (P4.15) — 142.0 МБ за 28 мин 04 с = 5.06 МБ/мин ≈ 84 КБ/с (`t0` 11:56:40 → `t2` 12:24:44, 2026-08-05). Replica lag: не измерено — `select count(*) from pg_stat_replication` → **0** строк | WAL — обе пары `pg_current_wal_lsn()`/`pg_wal_lsn_diff()`, времена см. `artifacts/p4-run-metrics.json`/`artifacts/p4-rerun-metrics.json`; replica lag — `select count(*) from pg_stat_replication;`, снято 2026-08-05 15:05:16 UTC, tree `ae45f4c` | WAL pressure — измерим постоянно дельтой LSN на любом прогоне (снят дважды, разброс 2.8× между прогонами объяснён Д-1, `findings/P4-load-baseline.md` §9.6/§9.7); replica lag — структурно неизмерим на одиночном primary без физически применяющего WAL standby (`research/08-db-review.md` §11, F-12); появится только при развёртывании реплики — вне Scope P7 (Scope Excluded, D71) |
| **Отставание autovacuum и рост bloat** | `n_dead_tup`/`n_live_tup`/`last_autovacuum`/`autovacuum_count` из `pg_stat_user_tables` (доступно постфактум); строгий bloat % — только `pgstattuple` (`dead_tuple_len`/`table_len`) | Доля мёртвых строк — **да**, постфактум прямо сейчас; строгий bloat — **нет**, расширение не установлено | `prc_procurements`: 778 dead / 17 943 live = **4.16 %** мёртвых, `last_autovacuum` 2026-08-05 12:23:43 UTC, `autovacuum_count` = 33; `prc_revisions_2026_08`: 0 dead (0 %), 15 запусков; `prc_events_2026_08`: 0 dead (0 %), 16 запусков; `prc_fetches_2026_08`: 0 dead (0 %), 1 запуск. Строгий bloat: `select count(*) from pg_extension where extname='pgstattuple'` → **0** | `select relname, n_live_tup, n_dead_tup, round(100.0*n_dead_tup/nullif(n_live_tup+n_dead_tup,0),2), last_autovacuum, autovacuum_count from pg_stat_user_tables where relname in (…)`, 2026-08-05 15:05:16 UTC, tree `ae45f4c` | Доля мёртвых строк измерима постоянно постфактум (снята трижды — 4.5 % → 4.1 % → 4.16 %, в диапазоне шума на прогонах и сейчас); autovacuum не отстаёт ни на одном срезе (последний запуск на самой горячей таблице — минуты/часы назад на каждом снятии). Строгий bloat % структурно неизмерим до `CREATE EXTENSION pgstattuple` (F-12, адресат P7.4) |
| **Конкуренция чтения с ingestion за shared buffers / CPU / checkpoint I/O** | Серия сэмплов `pg_stat_activity` (`wait_event_type`/`wait_event`, `pg_blocking_pids`) ВО ВРЕМЯ прогона; per-query профиль (что именно создаёт нагрузку) — только `pg_stat_statements` | Частично. Первый прогон (P4.7) — **да**, полная серия 60 сэмплов; контрольный прогон (P4.15) — **нет** (дефект скрипта-сэмплера, только 3 точечных сэмпла); per-query — **нет**, структурно | Первый прогон: 60 из 60 сэмплов = `Client/ClientRead` (бэкенд ждёт ответ источника ЕИС, не БД), ни одного `Lock`/`LWLock`/`IO`/`BufferPin`. Контрольный прогон: 3 из 3 точечных сэмпла — тот же `Client/ClientRead`. `pg_stat_statements`: `select extname from pg_extension` → отсутствует, тот же результат на обоих прогонах и сейчас | Серии — во время прогонов P4.7 (06:35–07:05) и P4.15 (11:56–12:24), команды и точки — `artifacts/p4-run-metrics.json`/`artifacts/p4-rerun-metrics.json`; наличие `pg_stat_statements` перепроверено постфактум 2026-08-05 15:05:16 UTC, tree `ae45f4c` | Contention по wait events измерим ТОЛЬКО во время прогона сериями сэмплов, не постфактум; на всей доступной фактуре (63 сэмпла между двумя прогонами) сигнала contention с БД не найдено ни разу — во всех случаях бэкенд ждёт клиента/сеть, не Postgres, но серия P4.15 неполная (Pending Work — пересдать на следующем боевом окне, не реконструируется). Per-query write pressure структурно неизмерим без `pg_stat_statements` (F-12, адресат P7.4) |
| **Потребность в РАЗНЫХ индексах / fillfactor / autovacuum / retention для write- и read-контуров** | Сравнение конфигурации autovacuum/retention/индексов между write- и read-контурами; фактическое использование индексов на горячих таблицах (`idx_scan`) | Да, постфактум — `config/partitioning.php` (конфигурация) + `idx_scan` на горячих партициях прямо сейчас | `config('partitioning.*')` — ОДИН `retention`/`premake`/`interval` на таблицу без деления read/write (`config/partitioning.php:16-66`); read-контура как отдельного потребителя данных в системе нет (нет API выдачи, `findings/repo-recon-2026-07-31.md` §12, OQ-5). `idx_scan` на горячих партициях сейчас: `prc_procurements` — 8 из 15 индексов без единого скана; `prc_fetches_2026_08` — 1 из 3; `prc_revisions_2026_08`/`prc_events_2026_08` — 0 из 3 без скана (использованы все — партиции новые, под запись). `stats_reset` глобально пуст | `select relname, count(*) filter (where idx_scan=0), count(*) from pg_stat_user_indexes where relname like 'prc_procurements%' or … group by relname` + `select stats_reset from pg_stat_database where datname='agelto'` → NULL, 2026-08-05 15:05:16 UTC, tree `ae45f4c`; `config/partitioning.php` — чтение кода, тот же tree | Измеримо постфактум как «текущее состояние», но сам критерий предполагает сравнение ДВУХ контуров: второй (read) контур, с которым сравнивать write-конфигурацию, в системе физически не существует (нет отдельного read-serving пути/реплики/API) — строка не о выборе топологии, а о факте отсутствия объекта сравнения |
| **Независимое масштабирование чтения** | Read-serving слой, который можно масштабировать независимо от write-контура (реплика или отдельный read API) | Нет — структурно; боевой прогон был чистым ingestion, без параллельного read-трафика от потребителей | Не измерено — `findings/repo-recon-2026-07-31.md` §12: API-контур выдачи не реализован; `research/08-db-review.md` §11 не находит read-реплики в рантайме кластера; положения (3)–(5) модели D72 (неизменяемый после верификации продукт, схемы `pub`/`raw`, кэш/пагинация/rate limit выдачи) не реализованы (OQ-5, отдельный план) | — (предмет измерения отсутствует в системе; подтверждено чтением `repo-recon` §12 и `research/08-db-review.md` §11, tree `ae45f4c`, 2026-08-05) | Структурно неизмеримо на данном прогоне — не потому что метрика не снята инструментом, а потому что сам объект измерения (независимый read-контур) в системе не существует; станет измеримым после реализации read-слоя (положения D72 (3)–(5), OQ-5, отдельный план) |

## Метрики, снимаемые ТОЛЬКО во время прогона и отсутствующие в baseline

Из пяти критериев два целиком (replica lag, per-query contention) и часть третьего (полная серия
wait events контрольного прогона) не реконструируются постфактум по Implementation Rule 2 — они
поимённо названы «не снято» в таблице выше, причина указана структурно (нет реплики; нет
`pg_stat_statements`; дефект скрипта-сэмплера контрольного прогона). WAL-скорость и wait events
доступны только как факт УЖЕ снятого во время двух прошедших прогонов — новых прогонов ради этого
item item не запускает (Scope Excluded).

## Досъём постфактум из каталога — сырые данные снятия 2026-08-05 15:05:16 UTC (tree `ae45f4c`)

Полный вывод — `artifacts/p7-db-metrics.json`. Разделы: `stats_reset`, установленные расширения,
`pg_stat_replication` (0 строк), dead tuples/autovacuum по четырём горячим партициям, размеры
(total/heap/indexes), `idx_scan` (unused/total) по тем же таблицам, число партиций на пять
партиционированных родителей (`prc_events`/`prc_revisions`/`llm_calls`/`prc_fetches`/
`prc_operation_logs` — по 3 каждая, окно `[2026-08-01, 2026-11-01)`, DEFAULT-партиции нет — тот же
факт, что F-06 `research/08-db-review.md`, здесь не переоткрывается).
