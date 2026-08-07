# HANDOFF — 2026-08-07 — after P3.4

**Next:** `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.5` (§3 Routing/roadmap.md: Exec = plan-exec,
implementation/medium; сиды поверх уже заведённых полей P3.1+P3.3, `Inputs` ссылается на их
Deliverables)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing item'а, решения запинены брифом, гейты детерминированы |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать P3.5: привести `CrawlerProfileSeeder` к двум профилям из брифа в их ПОЛНОМ виде (архивный + актуальный, поля сортировки и `stop_policy`) |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.5` |

```
/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.5
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P3: **P3.1 🟠**
(сортировка в фильтре профиля) + **P3.2 🟠** (прогон на очереди) + **P3.3 🟢** (политика остановки
«только актуальные данные») + **P3.4 🟢** (операторские действия над прогоном). P3.4:
`StartCrawlerRunAction` (`start()`/`restart()`/`resume()`, опциональный `$mode` override) +
`RequestRunStopAction` (`pause()`/`cancel()`, идемпотентно) — единственный носитель правила «можно
ли запускать/останавливать», консоль (`crawler:run-fresh`/`crawler:run-archive`) и админка зовут
один и тот же класс; исключения `CrawlerRunAlreadyActive`/`RunAlreadyTerminal`
(`app/Exceptions/Procurement/Crawler/`, подобласть дописана в `DomainLayoutTest`); три кнопки на
`CrawlersTable` («Запустить»/«Запустить с начала»/«Продолжить»), две на `RunsTable`+`ViewRun`
(«Остановить»/«Отменить») — право строкой ability `RunPermission::Update`, guard И в `visible()`,
И внутри Action; `RunResource` докблок и `docs/04_Operators/how-to/restart-failed-run.md`
переписаны под кнопки. 11 новых тестов (`CrawlerRunActionsTest`); Validation: целевой фильтр
20 passed (на `5b237ab0`), `pint`/`phpstan` зелено без роста baseline, полный прогон 18 failed/1176
passed — тот же предсуществующий baseline (Δ=0, +11 моих зелёных). Pending Work — кнопки
`CrawlersTable` не передают `from`/`to`: для Archive-режима профиля админ-старт упадёт
`LogicException` внутри job'а (окно дат архивного профиля живёт только как `--from/--to` консоли,
P3.5 Implementation Rules п. 2), консольный путь не затронут. Known Deviations: второй
exception-файл `RunAlreadyTerminal.php` сверх Files item'а (механически необходим Scope Included
п. 3); Pre-mutation Task Contract собран после начала мутаций (та же перестановка порядка, что
P3.3).

**Remaining:** 41 item из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Внутри P3 — цепочка последовательна по данным
(`research/04-p3-runs-design.md` §7): P3.1 + P3.2 + P3.3 + **P3.4** (все закрыты) → **P3.5** (сиды)
· P3.2 + P3.3 + P3.4 → **P3.6** (витрина, тоже разблокирована). Следующий по порядковому номеру —
**P3.5**.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context,
Completion Notes P3.1–P3.4) · `phases/P4.md`–`P9.md` · `research/04-p3-runs-design.md` (дизайн P3,
§6 ловушки, §7 порядок items) · `docs/03_Dev/crawling-pipeline.md` §«Вход: команды-диспетчеры,
обход — на очереди» (актуализирован P3.2; P3.3/P3.4 доки не трогали кроме
`restart-failed-run.md`) · `docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md`
(актуализированы P2.6, P3.1–P3.4 их не трогают).

**Open risks:**
- **Pending Work P3.2** (унаследовано): `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж
  ТЕКУЩЕГО date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком. Для fresh-режима
  (единственного, что `CrawlRunJob` реально диспетчит из P3.2/P3.3/P3.4) не проявляется;
- **Pending Work P3.4** (новое): admin-кнопки `CrawlersTable` не передают `from`/`to` — старт
  Archive-профиля из админки упадёт `LogicException` внутри `CrawlRunJob::handle()`; консольный
  путь (`--from/--to`) не затронут;
- **only_fresh_margin_minutes = 60 не калиброван боевым прогоном** (P3.3) — прода нет в скоупе
  плана, занижение = пропущенные обновления, завышение = лишние блоки;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3;
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
статус прогона после `scan()` (см. Open risks выше); admin-старт Archive-профиля без `from`/`to`
(Open risks выше). Open questions: Q9, Q10, Q13, Q14 (`prc_daily_stats`, вне скоупа P3), Q15, Q16 —
`Exploration`, ни один не блокирует P3.5.
