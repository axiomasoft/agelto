# HANDOFF — 2026-08-07 — after P2

Фаза P2 **терминальна**: все 6 items закрыты (P2.2/P2.4 🟢, P2.1/P2.3/P2.5/P2.6 🟠).

**Next:** manual: implementation/high — P3.1 (§3 Routing/roadmap.md: Exec = manual;
инвертированная семантика `sortDirection` — coupling с P2 по `EisSearchFilter`)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — открытые design-развилки уровня item (roadmap.md §Effort) |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P3.1 (сортировка выдачи в фильтре профиля) голым промптом item'а — Exec = manual |
| Команда | `—` |

```
/model sonnet
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P3.1
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). Исполнение фазы P2 (6/51
🟢/🟠 items плана): **P2.1 🟠, P2.2 🟢, P2.3 🟠, P2.4 🟢, P2.5 🟠, P2.6 🟠** — доска 6/51 🟢+🟠
(2 чистых 🟢, 4 с зафиксированными process/дифф-вне-Files отклонениями), фаза P2 терминальна.
Заведён носитель карты «код ОКПД2 → id НСИ» (`prc_okpd2_nodes`), скоуп ОКПД2 структурно
обязателен в `EisSearchFilter`, `EisCsvUrlBuilder` резолвит его в запрос ЕИС, форма профиля несёт
доведённый мультиселект, команда `crawler:okpd2-sync` умеет пересобрать карту и засечь дрейф id,
мёртвая ветка ОКПД2-листинга снесена целиком (код, схема, витрина, доки).

**Remaining:** 45 items из 51. Порядок жёсткий: P2 → P3 → P4 → P5 по coupling-поверхности (D98);
P8 — после P2–P6 (D4/D83); P9 — после P8. Следующая фаза по roadmap — **P3** (уже детализирована,
`P3.1` — `plan-run (manual)`, `implementation/high`).

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
развилки № 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска, P3.1–P3.6 уже расписаны) ·
`phases/P2.md` (Phase Handoff, Completion Notes P2.1–P2.6) · `phases/P3.md`–`P9.md` ·
`research/11-p2.2-scope-gate-perimeter.md` (D108–D110, периметр гейта скоупа) ·
`docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md` (актуализированы P2.6).

**Open risks:**
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2, ни
  один item плана его не адресует;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P2.6, не чинилось;
- `pint --test` — 12 предсуществующих style issues на 950 файлах, ни один в путях P2 — вне Scope;
- строка «полный прогон … — зелено» в `Validation` остальных 45 незакрытых items по-прежнему
  ложна на текущем тестовом дереве (D110) — приёмка следующих items идёт дельтой против baseline,
  не «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора.

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2 — Phase Context P2 прямо исключает); ветка 223-ФЗ (Q10); предел `okpd2Ids` и
долгосрочная стабильность id НСИ сверх разового детекта дрейфа P2.5. Open questions: Q9, Q10,
Q13, Q15, Q16 — `Exploration`, ни один не блокирует P3.
