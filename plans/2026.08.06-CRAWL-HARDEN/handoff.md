# HANDOFF — 2026-08-07 — after P3.5

**Next:** `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.6` (§3 Routing/roadmap.md: Exec = plan-exec,
implementation/medium; витрина поверх готовых носителей P3.2+P3.3+P3.4, `Inputs` ссылается на их
Deliverables — последний item фазы P3)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing item'а, гейты детерминированы, N+1 на партиционированной `prc_fetches` обязывает `Review = full` |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать P3.6: витрина истории прогонов и статистика в админке (последний item фазы P3) |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.6` |

```
/task:plan-exec 2026.08.06-CRAWL-HARDEN P3.6
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P3: **P3.1 🟠**
(сортировка в фильтре профиля) + **P3.2 🟠** (прогон на очереди) + **P3.3 🟢** (политика остановки
«только актуальные данные») + **P3.4 🟢** (операторские действия над прогоном) + **P3.5 🟠**
(сиды двух профилей). P3.5: `CrawlerProfileSeeder::seedArchiveProfile()`/`seedFreshProfile()`
дополнены полями сортировки (P3.1) и `stop_policy` (P3.3) — архив: `ListingSortField::Published`/
`sortAscending: true`/`RunStopPolicy::Default`; fresh: `ListingSortField::Updated`/
`sortAscending: false`/`RunStopPolicy::OnlyFresh`. Тест расширен в существующем
`tests/Feature/Seeders/EisSeedersTest.php` (новый кейс «сид заводит профили с сортировкой выдачи
и политикой остановки из брифа») — отдельный `CrawlerProfileSeederTest.php` не заведён, т.к.
`EisSeedersTest.php` уже покрывает состав полей и идемпотентность того же сидера тем же сетапом
(`EisPlatformSeeder`). Validation: целевой фильтр `EisSeedersTest` 9 passed/34 assertions (на
`fc3df115`); `db:seed --class=CrawlerProfileSeeder` код 0 (после `artisan migrate --force` — dev-БД
отставала на 4 миграции P2/P3); `pint --test`/`phpstan analyse` (полный прогон, 578 файлов) —
зелено без роста baseline; полный прогон `artisan test --no-tia --compact` — 18 failed/1177 passed
(предсуществующий baseline `8728723e`, Δ=0 по failed, +1 мой зелёный). Статус 🟠 — item-коммит
`fc3df115` несёт дифф вне `Files` item'а (`EisSeedersTest.php` вместо заявленного условно нового
файла) — material-отклонение по status-rule.

**Remaining:** 40 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Внутри P3 — цепочка последовательна по данным
(`research/04-p3-runs-design.md` §7): P3.1 + P3.2 + P3.3 + P3.4 + **P3.5** (все закрыты) →
**P3.6** (витрина, последний item фазы). Следующий по порядковому номеру — **P3.6**.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context,
Completion Notes P3.1–P3.5) · `phases/P4.md`–`P9.md` · `research/04-p3-runs-design.md` (дизайн P3,
§6 ловушки, §7 порядок items) · `docs/03_Dev/crawling-pipeline.md` §«Вход: команды-диспетчеры,
обход — на очереди» (актуализирован P3.2; P3.3–P3.5 доки не трогали кроме
`restart-failed-run.md` в P3.4) · `docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md`
(актуализированы P2.6, P3.1–P3.5 их не трогают).

**Open risks:**
- **Pending Work P3.2** (унаследовано): `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж
  ТЕКУЩЕГО date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком. Для fresh-режима
  (единственного, что `CrawlRunJob` реально диспетчит из P3.2/P3.3/P3.4) не проявляется;
- **Pending Work P3.4** (унаследовано): admin-кнопки `CrawlersTable` не передают `from`/`to` — старт
  Archive-профиля из админки упадёт `LogicException` внутри `CrawlRunJob::handle()`; консольный
  путь (`--from/--to`) не затронут;
- **only_fresh_margin_minutes = 60 не калиброван боевым прогоном** (P3.3) — прода нет в скоупе
  плана, занижение = пропущенные обновления, завышение = лишние блоки;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3, не чинилось; dev-БД теперь домигрирована до
  `…100081` (P3.5 db:seed-гейт), но `fresh --seed` баг это не затрагивает;
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
`Exploration`, ни один не блокирует P3.6.
