# HANDOFF — 2026-08-07 — after P3.3

**Next:** `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.4` (§3 Routing/roadmap.md: Exec = plan-exec,
implementation/medium; операторские действия над прогоном поверх готового механизма P3.2, `Inputs`
ссылается на P3.2 + P3.3 Deliverables)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing item'а, авторизация и отказ второму запуску обязывают Review=full, но тир — implementation/medium |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать P3.4: пять операторских действий («Запустить»/«Запустить с начала»/«Продолжить»/«Остановить»/«Отменить») + внятный отказ команд при активном прогоне |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.4` |

```
/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.4
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P3: **P3.1 🟠**
(сортировка в фильтре профиля) + **P3.2 🟠** (прогон на очереди) + **P3.3 🟢** (политика остановки
«только актуальные данные»). P3.3: enum `RunStopPolicy` (`Default`/`OnlyFresh`) + миграция
`prc_crawlers += stop_policy` (text, default `default`); третий гейт записи `Crawler::saving()` —
`OnlyFresh` легален только при `mode=fresh`; `CrawlerFactory::onlyFresh()`; `config('crawler.fresh.
only_fresh_margin_minutes')` = 60 (не калибровано); `FreshRunProcessor::resolveOnlyFreshThreshold()`
считает порог один раз на старте (время СТАРТА предыдущего завершённого прогона минус запас), сравнение
`reachedPreviousRun()` — по `oldestUpdateSeen` ПОСЛЕ инжеста, граница «ровно на пороге» = остановка;
все три стоп-условия fresh-прогона (новое + два существующих) пишут различимый
`stats.stop_reason`/опционально `stop_threshold_at` через `markStopReason()` (прямое присвоение
`$run->stats`, персистится ближайшим `update()` — `CrawlerRunManager.php` не тронут, вне `Files`
item'а); поле `Select::make('stop_policy')` («Режим работы») в `CrawlerForm` с `helperText` о
предпосылке сортировки. 7 новых тестов + 2 расширенных существующих (`FreshRunProcessorTest` ×7,
`CrawlerFormTest` ×2 новых); Validation: целевой фильтр 36 passed, `pint`/`phpstan` зелено без
роста baseline, полный прогон 18 failed/1165 passed — тот же предсуществующий baseline (Δ=0).
Known Deviations: Pre-mutation Task Contract собран/провалидирован (`allow`/`allow`) ПОСЛЕ начала
мутаций, а не до — процессная перестановка порядка, оба предиката зелёные постфактум.

**Remaining:** 42 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Внутри P3 — цепочка последовательна по данным
(`research/04-p3-runs-design.md` §7): P3.1 + P3.2 + P3.3 (все закрыты) → **P3.4** (кнопки) ·
P3.1 + P3.3 → P3.5 (сиды) · P3.2 + P3.3 + P3.4 → P3.6 (витрина). Следующий по цепочке — **P3.4**.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context,
Completion Notes P3.1/P3.2/P3.3) · `phases/P4.md`–`P9.md` · `research/04-p3-runs-design.md` (дизайн
P3, §4 «режим только актуальные», §6 ловушки) · `docs/03_Dev/crawling-pipeline.md` §«Вход: команды-
диспетчеры, обход — на очереди» (актуализирован P3.2; P3.3 доки не трогала — Scope Excluded) ·
`docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md` (актуализированы P2.6, P3.1–P3.3 их не
трогают).

**Open risks:**
- **Pending Work P3.2** (унаследовано): `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж
  ТЕКУЩЕГО date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком, а безусловный
  `complete()` в конце их циклов затирает `paused`/`cancelled` обратно в `completed`. Для
  fresh-режима (единственного, что `CrawlRunJob` реально диспетчит из P3.2/P3.3) не проявляется.
  Тот же item решает и второй пункт — `ArchiveWindowScanner` не читает `crawler.run.checkpoint_seconds`;
- **only_fresh_margin_minutes = 60 не калиброван боевым прогоном** (P3.3 Why/Code Guidance) — прода
  нет в скоупе плана, занижение = пропущенные обновления, завышение = лишние блоки;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3, ни
  один item плана его не адресует;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3, не чинилось;
- `pint --test` — 12 предсуществующих style issues на репозитории вне путей P3 — вне Scope;
- **полный прогон `artisan test --no-tia --compact` красен на baseline** (18 failed: `Prepare/
  Archive`, `Prepare/Gsfx`, `TextGridRenderer`, `Ai/Llm/CoreKnowsNoDocumentType` — распаковка
  7z/`tmp_extract`/`memory_limit` и несвязанные с P3 семейства) — вне Scope P2/P3, приёмка следующих
  items по-прежнему идёт дельтой против baseline (`8728723e`), не «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора;
- **посторонний дифф в дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  унаследовано из P3.1 handoff, эта сессия их также не коснулась (не коммитила, не стэшила).

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2/P3); ветка 223-ФЗ (Q10); предел `okpd2Ids` и долгосрочная стабильность id НСИ
сверх разового детекта дрейфа P2.5; обёртки-дневные-циклы Archive/Delta/Backlog не перечитывают
статус прогона после `scan()` (см. Open risks выше). Open questions: Q9, Q10, Q13, Q14
(`prc_daily_stats`, вне скоупа P3), Q15, Q16 — `Exploration`, ни один не блокирует P3.4.
