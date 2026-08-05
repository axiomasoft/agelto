# HANDOFF — 2026-08-05 — after P7.8

**Next:** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8` — фаза P8 (деградированный источник сметы)
заведена скелетом (D120), детализация до DoR требует нового design-захода, ПОСЛЕ него — повторный
`finish` (`plan-protocol` §5: контракт фазы появился после последнего `finish`).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — детализация фазы до DoR, контракт-блоки, Routing |
| Context | NEW SESSION — шаг-не-item |
| Суть | Детализировать P8 (деградированный источник сметы: ничего не теряем, помечаем неопределённость) до DoR |

```
/task:plan-design 2026.07.31-SYS-INTEGRITY P8
```

**Сделано этим заходом (`plan-exec P7.8`, sonnet/low, 2026-08-05).**

- **P7.8 закрыт 🟠 Done with deviations**, item-коммит **`0c1840f`** — один файл:
  `research/01-gap-analysis.md` (Code Guidance item'а: «Кода приложения нет — только доки и
  итоговый отчёт», Files-скоуп доков синхронизирован пофазно раньше).
- **Приёмочный прогон на дереве `3047dc7`:** `artisan test` — 1088 passed / 6 failed / 1 skipped
  (те же шесть предсуществующих падений, что в Known Deviations P7.7 — не от диффа P7.8);
  `phpstan analyse` — No errors, baseline 56 записей (не выросло, входное «74» в тексте item'а
  устарело); `pint --test` — **FAIL, 11 style issues** в 924 файлах, ни один путь не входит в
  `Files` P7.8 → правка вне Scope (Code Guidance запрещает трогать код приложения) → Known
  Deviation, не тихий пропуск.
- **Доки:** `grep -rn "prc_" docs/03_Dev/*.md .ai/guidelines/*.md` не содержит имён
  таблиц/индексов, которых нет в живом каталоге БД (сверено с `pg_tables`/`pg_indexes`). Доки по
  P1–P7 уже синхронизированы пофазно каждым doc-item'ом на закрытии (P1.8/P1.10, P2.7, P3.5, P4.8,
  P5.8, P6.6, P7.3) — P7.8 добавок в `CLAUDE.md`/`.ai/guidelines/**` не потребовал.
- **`research/01-gap-analysis.md`** — сводная таблица дополнена колонкой «Итог (P7.8)»: девять
  пунктов брифа (+2б, +7б) получили вердикт фактического исполнения со ссылкой на закрывший item и
  D-решение.
- **`open-questions.md`** — сверено: OQ-1 (Resolved → D71/D72), OQ-2 (Resolved → D103), OQ-3
  (Resolved → D117), OQ-4 (Exploration), OQ-5 (Decision pending — отдельный план), OQ-6/OQ-7
  (Exploration), OQ-8 (Resolved → D113), OQ-9 (Closed → D134) — все статусы уже актуальны, правок
  не потребовалось.
- **Status Board / Phase Handoff** сверены: `plan.md` §4 счётчики `Items 🟢/всего` совпадают с
  `## Phase Status` каждой фазы; `## Phase Handoff` P1–P6 заполнены содержательно (заметка
  «P2/P5 не собран» из прошлого handoff'а — про формальный `plan-close`, не про отсутствие
  текста); P7 Phase Handoff собран этим item'ом.
- **Фаза P7 терминальна** (4/8 🟢: P7.1/P7.2/P7.3/P7.6 · 4/8 🟠: P7.4/P7.5/P7.7/P7.8).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P7 4/8** 🟠 Done with deviations — фаза терминальна.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **Pint — 11 предсуществующих style issues вне Scope P7.8**: фикс —
  `docker compose exec app ./vendor/bin/pint` (автофикс) отдельным быстрым item'ом/коммитом;
  список файлов — `phases/P7.md` P7.8 Completion Notes.
- **`## Phase Handoff` P2/P5/P6 не прогонялся через формальный `plan-close`** — bookkeeping-хвост
  (контент присутствует), продолжение плана НЕ блокирует.
- **Объём, вынесенный P7.6/P7.7 в отдельный план** (`research/10` §6.2–§6.3, основание D59/D134):
  Д-6/Д-7/Д-8 (снапшоты Horizon/`pulse:trim`/`queue:prune-failed` не расписаны), Д-10 (claim на
  расписанные crawler-команды), Д-11 (`routes/console.php` в БД на каждый artisan-вызов), Д-12
  (`Http::preventStrayRequests()`), тесты расписания/очередей, развязка ретрая скачивания.
- **6 предсуществующих failing-тестов** (Known Deviations P7.7/P7.8) — вне Scope P7, фикс-
  предложение по каждому названо, ни один не блокирует.
- **Четыре строки гейта владельца P7.1** (`research/08-db-review.md` §12): F-07 (13 пустых колонок
  `prc_procurements`), F-09 (8 неиспользуемых индексов), F-01b (индексы azguard), cache-гейт
  (удаление `cache`/`cache_locks`).
- **OQ-4/OQ-5/OQ-6/OQ-7 открыты** — решения владельца, вне Scope этого плана.
- **`agelto_postgres_pre_p74`** — стоп-контейнер, требует `docker rm` владельцем вне харнесса.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/01-gap-analysis.md` — итоговая трассировка «девять
  пунктов брифа → результат», колонка «Итог (P7.8)».
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` — OQ-1…OQ-9, все статусы актуальны.
- `plans/2026.07.31-SYS-INTEGRITY/phases/P7.md` P7.8 Completion Notes — числа приёмочного прогона
  с tree-якорем, провенанс каждой проверки; `## Phase Handoff` — сводка фазы P7.
- `plan.md` §4 Phase Index (P7 `4/8` 🟠) · §6 Update Log (строка P7.8).

**Open risks:**

- **Наблюдаемость сама не обслуживается** (Д-6/Д-7/Д-8, вынесено в отдельный план): снапшоты
  Horizon не снимаются, `pulse_*` не прунятся, `failed_jobs` не читается/не чистится.
- **Очередь `ai_shadow` имеет supervisor, но контур ещё не включён** ни на одном маршруте.
- **Обслуживание партиций (F-13) — ЧАСТИЧНО адресовано**; видимость отказа `run_maintenance()` не
  реализована. Партиции кончаются `2026-11-01`.
- **`org_organizations` живёт по UPDATE-модели, будучи продуктовой таблицей** — решение владельца
  по OQ-5, вне Scope этого плана.
- **Подтверждение D71 остаётся частичным** до первого боевого извлечения смет.
- **Незакоммиченный чужой диф** в дереве (снимать `git status --porcelain` перед следующей
  мутацией): `.env.example`, `.swissknife.json`, `.swissknife/`, `.swissknifeman/`,
  `config/cms.php`, `database/seeders/User/`, `docker/php/conf.d/pcov-dev.ini` +
  правка `docker-compose.yml`/`docs/03_Dev/process/testing.md` (PCOV dev-контур, не от диффа
  плана), `estimates/gemini-analys.md`.
- **`.env` не читается харнессом** — `docker compose exec` требует `MINIO_ROOT_PASSWORD` в
  shell-окружении инвокации (снимается из env уже работающего `agelto_minio`).
  **`docker compose {up,restart,down} postgres` сломан** до ручного `docker rm
  agelto_postgres_pre_p74` владельцем — до этого управлять контейнером напрямую через `docker`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** снятие `MINIO_ROOT_PASSWORD` из env уже работающего `agelto_minio`; управление
  `postgres` через raw `docker` вместо `docker compose` до ручного `docker rm
  agelto_postgres_pre_p74`.
- **Deferred:** pint 11 style issues (Pending Work выше); формальный `plan-close` для P2/P5/P6;
  объём Д-6/Д-7/Д-8 + §6.2–§6.3 `research/10` — отдельный план; четыре строки гейта владельца P7.1;
  положения (3)–(5) модели D72 — OQ-5, отдельный план; 6 предсуществующих failing-тестов.
- **Open questions:** OQ-4 (браузерный канал прокси), OQ-5 (продуктовый контур), OQ-6
  (типизированный носитель семантики кейсов enum), OQ-7 (переписывание текстов промтов).
