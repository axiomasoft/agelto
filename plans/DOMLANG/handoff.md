# HANDOFF — 2026-07-10 — after P6.2

**Next:** `P7.1` — фаза P7 (глоссарий v2 + OCDS mapping) ещё скелет (0/2, DoR не пройден) → следующий шаг
не `plan-exec`, а `plan-design`.

| Параметр | Значение |
|:--|:--|
| Model | opus/high |
| Thinking | high — детализация фазы: глоссарий v2 + OCDS mapping (D3/ADR-0007) требуют содержательного дизайна items, не механики |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Детализировать фазу P7 (Phase Context, DoR, items P7.1/P7.2) по прецеденту детализации P1–P6 |

```
/task:plan-design DOMLANG P7
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 (2/2 🟢). P3 (3/3 🟢). P4 (1/2 🟠 — `P4.2` ⛔).
P5 (4/4 🟢). **P6 терминальна (2/2 🟢)** — spec drift убит: `P6.1` построил per-occurrence карту
(208 префиксов + 13 колонок вхождений мёртвых префиксов `doc_`/`est_`/`ai_`/`crw_`/`sys_`/`plt_`,
классификация RENAME/KEEP-v1/N-A по D30/D32), `P6.2` применил её механически — 29 RENAME-токенов
заменены anchored `\btoken\b`-заменой в 11 файлах (остальные 8 файлов карты содержали только
KEEP-v1/N-A и не менялись), column-drift `source_number→registry_number`/`source_guid→registry_guid`/
`source_status→source_stage` закрыт (кроме протокольного исключения D20 на `01-database.md:178`),
долг ADR-0009 (`02-crawling.md:159` — «и помечает статус») переписан на owner-модель (job, не мост).
Оба греп-гейта (G-under/G-over) + column-гейт + debt-гейт + double-prefix-гейт — все зелёные.

**Remaining:** P7 (глоссарий v2 + OCDS mapping) — скелет, требует `plan-design`. P8 (lifecycle-map/FSM),
P9 (финальный cross-phase review, включая владелец-item `/code-review ultra` P9.3, D9) — скелеты после P7.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §5 D29–D32 (scope/правило/долг P6), §4 Phase Index & Status Board (P6 2/2 🟢).
- `plans/DOMLANG/phases/P6.md` — P6.1/P6.2 Completion Notes + Phase Handoff (терминальная сводка фазы).
- `plans/DOMLANG/findings/P6-drift-map.md` — применённая карта (использовалась как единственный источник verdict'ов P6.2; больше не меняется).
- `docs/03_Dev/adr/0009-job-owns-document-transition.md` — источник правки долга `:159`.

**Open risks:**
- Остаток грепа в `docs/specs/v2`/`docs/03_Dev`/`docs-private` = 42 строки (28 KEEP-v1 + 14 N/A) —
  это заморожённый легитимный residue, не дрейф; будущий recon по той же регулярке должен сверяться
  с картой `P6-drift-map.md`, а не считать остаток дефектом заново.
- `docs-private/history/**` и `Old/` намеренно не тронуты (read-only v1-канон) — вне периметра
  любого будущего spec-drift-грепа.

**Workarounds/Deferred/Open questions:**
- Deferred: `docs-private/history/carryover-from-v1.md` §3/8 «исходная формулировка» — вне Scope P6 (не rename).
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений.
