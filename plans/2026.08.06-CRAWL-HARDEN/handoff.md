# HANDOFF — 2026-08-07 — after P3.2

**Next:** manual: implementation/high — P3.3 (§3 Routing/roadmap.md: Exec = manual; политика
остановки «только актуальные данные», стоит на порядке выдачи P3.1 и на контуре исполнения P3.2)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — стоп-условие по времени, ложное срабатывание молча пропускает обновления в проде (roadmap.md §Effort) |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P3.3 (режим «только актуальные данные», порог по старту предыдущего прогона) голым промптом item'а — Exec = manual |
| Команда | `—` |

```
/model sonnet
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P3.3
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P3: **P3.1 🟠**
(сортировка в фильтре профиля) + **P3.2 🟠** (прогон на очереди). P3.2: миграция
`prc_crawler_runs` += `stop_requested_at`/`stop_kind`/`stop_requested_by`/`queue_job_id`; enum
`RunStopKind` (`Pause`/`Cancel`); `RunStopGate::signal()`; `CrawlerRunManager::pause()`/`cancel()`/
`recordCheckpoint()`; гейт + checkpoint-бюджет (`crawler.run.checkpoint_seconds`=240) в
`FreshRunProcessor::run()`, гейт (без бюджета) в `ArchiveWindowScanner::scan()`; `CrawlRunJob`
(очередь `crawl`, `supervisor-crawl`, `ShouldBeUniqueUntilProcessing`, без свойств ретрая,
self-dispatch продолжения); `crawler:recover-stale` в расписании (`recoverStale()` перестал быть
мёртвым кодом); `crawler:run-fresh`/`crawler:run-archive` — тонкие диспетчеры (дифпатчат job вместо
синхронного вызова); `docs/03_Dev/crawling-pipeline.md` переписан под контур на очереди; 18 новых
тестов (`CrawlRunJobTest`, `RunStopGateTest`, расширены `CrawlerRunManagerTest`/
`RunFreshCrawlerTest`/`RunArchiveCrawlerTest`). Статус 🟠: полный прогон 18 failed на
предсуществующем baseline (те же `Prepare/Archive`/`Gsfx`/`TextGridRenderer`, что P3.1/P2.5/P2.6),
Δ=0 — детали `phases/P3.md` P3.2 Completion Notes.

**Remaining:** 43 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Внутри P3 — цепочка последовательна по данным
(`research/04-p3-runs-design.md` §7): P3.1 + P3.2 (оба закрыты) → **P3.3** (режим «только
актуальные») · P3.2 + P3.3 → P3.4 (кнопки) · P3.1 + P3.3 → P3.5 (сиды) · P3.2 + P3.3 + P3.4 → P3.6
(витрина). Следующий по цепочке — **P3.3**.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context,
Completion Notes P3.1/P3.2) · `phases/P4.md`–`P9.md` · `research/04-p3-runs-design.md` (дизайн P3,
§4 «режим только актуальные», §6 ловушки) · `docs/03_Dev/crawling-pipeline.md` §«Вход: команды-
диспетчеры, обход — на очереди» (актуализирован P3.2) · `docs/03_Dev/database.md`,
`docs/03_Dev/ocds-mapping.md` (актуализированы P2.6, P3.1/P3.2 их не трогают).

**Open risks:**
- **Pending Work P3.2** (новое): `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж
  ТЕКУЩЕГО date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком, а безусловный
  `complete()` в конце их циклов затирает `paused`/`cancelled` обратно в `completed`. Для
  fresh-режима (единственного, что `CrawlRunJob` реально диспетчит из P3.2) не проявляется.
  Предложение — `phases/P3.md` P3.2 Pending Work (три строки на файл, три файла, отдельный узкий
  fix-item перед тем, как `DeltaScanProcessor`/`BacklogRescanProcessor` включаются по расписанию).
  Тот же item решает и второй пункт Pending Work — `ArchiveWindowScanner` не читает
  `crawler.run.checkpoint_seconds` (архивный/delta/backlog обход пока без бюджетного requeue).
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3, ни
  один item плана его не адресует;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3, не чинилось;
- `pint --test` — 12 предсуществующих style issues на 958 файлах, ни один в путях P3 — вне Scope;
- **полный прогон `artisan test --no-tia --compact` красен на baseline** (18 failed: `Prepare/
  Archive`, `Prepare/Gsfx`, `TextGridRenderer` — распаковка 7z/`tmp_extract`/`memory_limit`) — вне
  Scope P2/P3, приёмка следующих items по-прежнему идёт дельтой против baseline (`8728723e`), не
  «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора;
- **посторонний дифф в дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  унаследовано из P3.1 handoff, эта сессия их также не коснулась (не коммитила, не стэшила).

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2/P3); ветка 223-ФЗ (Q10); предел `okpd2Ids` и долгосрочная стабильность id НСИ
сверх разового детекта дрейфа P2.5; обёртки-дневные-циклы Archive/Delta/Backlog не перечитывают
статус прогона после `scan()` (см. Open risks выше, новое P3.2). Open questions: Q9, Q10, Q13, Q14
(`prc_daily_stats`, вне скоупа P3), Q15, Q16 — `Exploration`, ни один не блокирует P3.3.
