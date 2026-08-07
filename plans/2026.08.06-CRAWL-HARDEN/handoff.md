# HANDOFF — 2026-08-07 — after P3

**Next:** «ЗАПУСК ВРУЧНУЮ: sonnet/high» — P4.1 (§3 Routing: `implementation/high`, `Exec = manual`;
носитель настроек лежит на coupling-поверхности `config/crawler.php` и формы профиля — неверное
слияние трёх уровней молча вернёт глобальные значения и обесценит калибровку боевым прогоном)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано Routing (`manual`), команда `plan-exec` пинит sonnet/medium и обязана отклонить этот item по гейту п. 2 |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать P4.1: трёхуровневый носитель настроек обхода (площадка → профиль → config) и единственный резолвер |
| Команда | `—` |

```
Прочитай plans/2026.08.06-CRAWL-HARDEN/plan.md (Context, Execution Rules, §3 Routing, Status Board),
plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P4.md
(Phase Context + P4.1 целиком), затем Required Reads item'а P4.1. Исполни P4.1 строго по его
Scope Included/Excluded, Implementation Rules, Code Guidance. Закрой item по полному протоколу
plan-protocol §8 (два коммита: item-коммит по Files + bookkeeping плана), запусти Validation
целиком, обнови phases/P4.md (Status/Completion Notes/Phase Status), plan.md (Status Board/Update
Log/Meta), handoff.md, journal.jsonl.
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). **Фаза P3 закрыта 🟠 Done with
deviations** — все 6 items терминальны (P3.3 🟢, P3.4 🟢, P3.6 🟢, P3.1 🟠, P3.2 🟠, P3.5 🟠),
`Phase Handoff` в `phases/P3.md` заполнен, `plan.md` Status Board синхронизирован (3/6, 🟠), docs-
sync проверен на уровне фазы — обновлено по items (`docs/03_Dev/crawling-pipeline.md` P3.2,
`docs/04_Operators/how-to/restart-failed-run.md` P3.4, `docs/04_Operators/reference/statuses.md`
P3.6), P3.1/P3.3/P3.5 доков не требовали (правки конфигурации фильтра/сидов без нового процесса
для оператора).

**Remaining:** 39 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Следующая по порядку — фаза **P4** (носитель настроек
обхода, привязка прокси, авто-дизейбл, общий бюджет, подача пачками, журналы), items связаны
последовательно по данным (`research/05-p4-reliability-design.md` §6), первый — **P4.1**,
`Exec = manual`.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110, развилки
№ 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context, Completion
Notes P3.1–P3.6, Phase Status все 6 терминальны, `Phase Handoff` заполнен) · `phases/P4.md`–`P9.md`
· `research/05-p4-reliability-design.md` (дизайн P4) · `docs/03_Dev/crawling-pipeline.md`
§«Вход: команды-диспетчеры, обход — на очереди» (актуализирован P3.2) ·
`docs/04_Operators/reference/statuses.md` (актуализирован P3.6 — разделы `RunStatus`/
`stop_reason`/`RunStopKind`) · `docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md`
(актуализированы P2.6, P3.1–P3.6 их не трогают).

**Open risks:**
- **Pending Work P3.6**: агрегаты `withCount`/`withSum` по `crawler_run_id` проверены только на
  тестовом масштабе (1–52 строки `prc_fetches`) — если боевой объём покажет полный скан партиций,
  агрегат ограничивается окном `started_at`…`finished_at` (if-then Implementation Rules item'а),
  это НЕ сделано превентивно;
- **Pending Work P3.2**: `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж ТЕКУЩЕГО
  date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком. Для fresh-режима
  (единственного, что `CrawlRunJob` реально диспетчит из P3.2/P3.3/P3.4) не проявляется —
  кандидат на узкий fix-item перед включением delta/backlog-режимов на расписании (P4);
- **Pending Work P3.4**: admin-кнопки `CrawlersTable` не передают `from`/`to` — старт Archive-
  профиля из админки упадёт `LogicException` внутри `CrawlRunJob::handle()`; консольный путь
  (`--from/--to`) не затронут; вне Scope брифа, отдельный item при запросе владельца;
- **only_fresh_margin_minutes = 60 не калиброван боевым прогоном** (P3.3) — прода нет в скоупе
  плана, занижение = пропущенные обновления, завышение = лишние блоки;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3, не чинилось;
- `pint --test` — 12 предсуществующих style issues на репозитории вне путей P3 — вне Scope;
- **полный прогон `artisan test --no-tia --compact` красен на baseline** (18 failed: `Prepare/
  Archive`, `Prepare/Gsfx`, `TextGridRenderer`, `Ai/PromptApply`, `Ai/PromptComposition`,
  `Ai/PromptRoot`, `Llm/GeminiProxyRouting`, `Architecture/CoreKnowsNoDocumentType`,
  `Procurement/Contour` — распаковка 7z/`tmp_extract`/`memory_limit` и несвязанные с P3 семейства)
  — вне Scope P2/P3, приёмка следующих items по-прежнему идёт дельтой против baseline (`8728723e`),
  не «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора;
- **посторонний дифф в дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  унаследовано из предыдущих сессий, эта сессия их также не коснулась (не коммитила, не стэшила).

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2/P3); ветка 223-ФЗ (Q10); предел `okpd2Ids` и долгосрочная стабильность id НСИ
сверх разового детекта дрейфа P2.5; обёртки-дневные-циклы Archive/Delta/Backlog не перечитывают
статус прогона после `scan()` (см. Open risks выше); admin-старт Archive-профиля без `from`/`to`
(Open risks выше); агрегаты `prc_fetches` не проверены на боевом объёме (см. Open risks выше).
Open questions: Q9, Q10, Q13, Q14 (`prc_daily_stats`, вне скоупа P3), Q15, Q16 — `Exploration`, ни
один не блокирует P4.
