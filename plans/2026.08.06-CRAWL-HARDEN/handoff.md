# HANDOFF — 2026-08-07 — after P3.1

P3.1 закрыт 🟠 (единственный item фазы P3, исполненный на сегодня): фаза P3 — 🟡 In progress
(0/6 чистых 🟢, 1/6 🟠, 5 items впереди).

**Next:** manual: implementation/high — P3.2 (§3 Routing/roadmap.md: Exec = manual; job-контур
`CrawlRunJob` + кооперативный стоп-гейт + подъём зависших — предпосылка для P3.4)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — инварианты таймаутов/уникальности/кооперативного стопа сходятся впервые (roadmap.md §Effort) |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P3.2 (CrawlRunJob, кооперативный стоп-гейт, подъём зависших) голым промптом item'а — Exec = manual |
| Команда | `—` |

```
/model sonnet
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P3.2
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P3: **P3.1 🟠**
— enum `App\Enums\Procurement\Crawler\ListingSortField` (`Published`/`Updated`), два поля
`EisSearchFilter` (`sortField`/`sortAscending`, конец конструктора, `ALLOWED_KEYS` + `rules()`),
`EisCsvUrlBuilder::sortParams()` эмитит `sortBy`/`sortDirection` из профиля с прежним дефолтом
(`UPDATE_DATE`/`false`) при `sortField === null`/`filter === null`, `archiveOverrides()`
по-прежнему безусловно перекрывает направление, два поля формы (`Select` сортировки + `Toggle`
«По возрастанию»); 6 новых тестов (пары sortBy/sortDirection на оба значения с проверкой точной
инвертированной строки, дефолт, archive-перекрытие, round-trip jsonb, форма). Статус 🟠: полный
прогон 18 failed на предсуществующем baseline (D110, `Prepare/Archive`/`Gsfx`/`TextGridRenderer` —
вне `Files` item'а) — детали `phases/P3.md` P3.1 Completion Notes.

**Remaining:** 44 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Внутри P3 — цепочка последовательна по данным
(`research/04-p3-runs-design.md` §7): P3.1 (закрыт) + P1.1 (закрыт) → **P3.2** (job-контур) →
P3.4 (кнопки) · P3.1 + P3.3 → P3.5 (сиды) · P3.2 + P3.3 + P3.4 → P3.6 (витрина). Следующий по
цепочке — **P3.2**, затем **P3.3** (режим «только актуальные», стоит на сортировке P3.1).

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context,
Completion Notes P3.1) · `phases/P4.md`–`P9.md` · `research/04-p3-runs-design.md` (дизайн P3, §6
ловушки инверсии/archiveOverrides/coupling) · `findings/P1-listing-params.md` §1 (вердикт: sortBy/
sortDirection действуют на CSV-выгрузке) · `findings/eis-params-carryover.md` §1 (инверсия
`sortDirection`) · `docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md` (актуализированы P2.6,
P3.1 их не трогает).

**Open risks:**
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3, ни
  один item плана его не адресует;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3.1, не чинилось;
- `pint --test` — 12 предсуществующих style issues на 951 файле, ни один в путях P2/P3 — вне Scope;
- **полный прогон `artisan test --no-tia --compact` красен на baseline** (18 failed: `Prepare/
  Archive`, `Prepare/Gsfx`, `TextGridRenderer` — распаковка 7z/`tmp_extract`/`memory_limit`) — вне
  Scope P2/P3, приёмка следующих items по-прежнему идёт дельтой против baseline (`2ff1f281`), не
  «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора;
- **посторонний дифф в дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  присутствовали в рабочем дереве уже на старте сессии P3.1 (снапшот `git status` до первого
  чтения плана), эта сессия их не коснулась (не коммитила, не стэшила); владельцу стоит
  зафиксировать их отдельным коммитом или явно указать, что они относятся к другой задаче.

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2/P3); ветка 223-ФЗ (Q10); предел `okpd2Ids` и долгосрочная стабильность id НСИ
сверх разового детекта дрейфа P2.5. Open questions: Q9, Q10, Q13, Q14 (`prc_daily_stats`, вне
скоупа P3), Q15, Q16 — `Exploration`, ни один не блокирует P3.2.
