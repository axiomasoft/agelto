# HANDOFF — 2026-07-10 — after P4.2

**Next:** Фаза P5 детализирована (4 items, DoR пройден). Дизайн-разведка опровергла предпосылку
скелета P5.1 (защита от двойного диспатча уже есть) и нашла обратный дефект — вечный `UniqueLock`
(`uniqueFor` = 0), который в проде запирает документ в `downloaded` навсегда (D25). Следующий шаг —
исполнение `P5.1` строго по ТЗ; правило решения продублировано в `workflow.js`
`DECISION_RULES['P5.1']`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — item полностью специфицирован (16 полей, готовые сигнатуры и факты в findings), решение принято дизайном |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | `PipelineJob::uniqueFor()` + ключ `ai.pipeline.unique_lock_minutes`, ADR-0009 (owner перехода — job, не мост), 2 регресс-теста, актуализация `docs-private/{risk-register,crawler-gotchas}.md` |

```
/task:plan-exec DOMLANG P5.1
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
P4 терминальна (1/2, 🟠 Done with deviations) — `P4.1` 🟢, `P4.2` ⛔ Skipped by decision (вердикт
`N = 0`, provenance не материализуется). P5 спроектирована, кода ещё не написано (0/4).

**Remaining:** P5 (4 items, готовы к exec) → P6–P9 (скелеты; каждая фаза перед exec требует
`/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board · §5 D-лог (D25 — вечный лок и запрет `Queued`; D26 —
  два частичных unique-индекса `prc_bids`; D27 — sync роли `winner`; D28 — удаление `customerOrgId`
  из write-path листинга) · §6 Update Log.
- `plans/DOMLANG/phases/P5.md` — 4 item'а с полными 16 полями.
- `plans/DOMLANG/findings/P5-pipeline-defects.md` — карта всех четырёх дефектов, готовый DDL
  индексов, механика `UniqueLock`/`RedisLock`/`ArrayLock`.
- `plans/DOMLANG/workflow.js` — `DECISION_RULES['P5.1']` (правило решения, не усмотрение).

**Open risks:**
- `customer_org_id` затирается в `null` на каждой новой ревизии листинга (D24/D28) — **живой дефект
  в коде**, чинится в `P5.4`, ещё не задет.
- Вечный `UniqueLock` в проде (D25) — **живой дефект**, чинится в `P5.1`. В тестах не
  воспроизводится (`ArrayLock` протухает за секунду) — не пытаться «доказать» его array-драйвером.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён;
  `02-crawling.md:159` вдобавок противоречит ADR-0009 (долг P5.1 → правит P6).
- `prc_protocols.source_number` (18 вхождений, 8 файлов) намеренно не переименован (D20).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: unique-индексы под естественные ключи `prc_financing`/`prc_items`/`prc_protocols`/
  `prc_awards` — тот же класс, что D26, но вне скоупа P5 (Scope Excluded фазы).
- Open questions (`open-questions.md`): Q7 (`Exploration`) — автопровижининг маппингов при создании
  реестра; Q8 (`Exploration`) — мультилотовые закупки (`lot_id` всегда `null`), влияет на D26/D27
  при появлении источника лотов. Q1–Q6 — `Resolved`.
