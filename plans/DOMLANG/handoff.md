# HANDOFF — 2026-07-10 — after P4.2

**Next:** Фаза P4 терминальна (`P4.1` 🟢 Done + `P4.2` ⛔ Skipped by decision — легальный исход
ADR-0008, вердикт `N = 0`). Следующий шаг — фаза P5 (**4** items: 3 исходных + `P5.4` из D24,
клоббер `customer_org_id`). P5 ещё скелет (0/4, Status Board `plan.md` не несёт заполненных 16
полей) → следующая команда обязана быть `/task:plan-design`, не `plan-exec`.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — детализация фазы P5: 4 item'а, один из них (`P5.4`) заводится design-time находкой D24, не из черновика |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Детализировать фазу P5 (Scope/DoR/Implementation Rules/Validation по 16 полям на item), особое внимание — `P5.4` (клоббер `customer_org_id`: приоритет слоя в `updateProcurementHead()`, не просто фильтр непустых) |

```
/task:plan-design DOMLANG P5
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
**P4 терминальна (1/2, 🟠 Done with deviations)** — `P4.1` 🟢 Done (proof-таблица нашла 2
кандидата, оба НЕ-R1), `P4.2` ⛔ Skipped by decision (вердикт `N = 0` → provenance не
материализуется, кода не написано). ADR-0008 `Consequences` дописан фактом отсутствия конфликтов.

**Remaining:** P5 (4 items, скелет) → P6–P9 (скелеты; каждая фаза перед exec требует
`/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board (P4 = 1/2, 🟠 Done with deviations) · §5 D-лог (D23 —
  правило R1/R2/R3; D24 — клоббер `customer_org_id` → P5.4) · §6 Update Log.
- `plans/DOMLANG/phases/P4.md` — Phase Handoff, P4.1/P4.2 Completion Notes.
- `plans/DOMLANG/findings/P4-provenance-proof.md` — вердикт `ВЕРДИКТ: 0 полей класса R1`.
- `docs/03_Dev/adr/0008-thin-provenance-slice.md` — `Consequences` несёт факт «P4.1 конфликтов не
  нашёл (2026-07-10, ссылка на proof)».
- `plans/DOMLANG/open-questions.md` Q4 — `Resolved → нет (P4.1)`.

**Open risks:**
- `customer_org_id` затирается в `null` на каждой новой ревизии листинга (D24, подтверждено P4.1
  как R2) — **живой дефект в коде**, чинится в `P5.4`, ещё не задет.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён.
- `prc_protocols.source_number` (18 вхождений, 8 файлов) намеренно не переименован (D20).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: —.
- Open questions (`open-questions.md`): Q7 (`Exploration`) — автопровижининг маппингов при создании
  реестра, решается при онбординге второй площадки. Q1–Q6 — `Resolved`.
