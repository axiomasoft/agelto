# HANDOFF — 2026-07-10 — after P5.4

**Next:** Фаза P5 терминальна (4/4 🟢). Следующая фаза — **P6** («Убить spec drift»), скелет
(0/2, Items 🟢/всего = 0/2 без заполненных полей) — требует детализации, не прямого exec.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — детализация фазы, правка `docs/specs/v2/**` под фактические имена + ADR-0009 |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Детализировать P6: убить spec drift (166 вхождений мёртвых префиксов, `02-crawling.md:159` vs ADR-0009) |

```
/task:plan-design DOMLANG P6
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
P4 терминальна (1/2, 🟠 Done with deviations) — `P4.1` 🟢, `P4.2` ⛔ Skipped by decision (вердикт
`N = 0`, provenance не материализуется). **P5 терминальна (4/4 🟢)** — `P5.1` 🟢 (TTL unique-лока,
ADR-0009), `P5.2` 🟢 (два частичных unique-индекса `prc_bids`, `NULLS NOT DISTINCT`, D26), `P5.3` 🟢
(`syncWinnerRoles()`, D27), `P5.4` 🟢 — мёртвый write-path листинга для `customer_org_id` удалён:
`ProcurementSnapshotData::$customerOrgId` (свойство + ключ `toSnapshotArray()`),
`ProcurementStoreRepository::storeProcurement()`/`updateProcurementHead()`,
`EisCsvRowMapper::map()` — единственный писатель колонки теперь `ProcurementPartyStoreRepository::
setCustomerOrg()` (detail-слой). `tests/Feature/Procurement/CustomerOrgOwnershipTest.php` — новый
regression-тест, зафиксирован red (клоббер воспроизведён) → green (после правки). `grep -rn
'customerOrgId' app/` = 0. `--filter=Revision`/`--filter=Discovery` зелёные, `pint --test` зелёный,
`migrate:fresh --seed` + полный сюит — 478 passed. Коммит `74666b2`.

**Remaining:** P6–P9 (скелеты; каждая фаза перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board (P5 = 4/4 🟢) · §5 D-лог (D28 — удаление `customerOrgId`
  из write-path листинга, закрыт P5.4) · §6 Update Log.
- `plans/DOMLANG/phases/P5.md` — 4 item'а, все закрыты с Completion Notes; Phase Handoff заполнен.
- `plans/DOMLANG/findings/P5-pipeline-defects.md` §4 — исходная карта клоббера `customer_org_id`.

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён;
  `02-crawling.md:159` противоречит ADR-0009 (правку спеки делает P6, D7).
- `prc_protocols.source_number` (18 вхождений, 8 файлов) намеренно не переименован (D20).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список; `.env.example`/директория репо на хосте
  закрыты Read/Edit-permission харнесса (не docker) — правки через `docker exec -u root agelto_app
  php -r '...'` + `chown 1000:1000` на изменённый файл.
- Модели `Bid`/`Protocol`/`ProcurementParty` не имеют Eloquent relations (`procurement()` и т.п.) —
  фабрики/тесты связывают через явные FK-поля, `->for()` в тестах на этих моделях недоступен.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в
  shell); правка `.env.example` — через `docker exec -u root` (Read/Edit-тулы харнесса блокируют
  путь напрямую), с обязательным `chown 1000:1000` после записи.
- Deferred: unique-индексы под естественные ключи `prc_financing`/`prc_items`/`prc_protocols`/
  `prc_awards` — тот же класс, что D26, но вне скоупа P5 (Scope Excluded фазы).
- Open questions (`open-questions.md`): Q7 (`Exploration`) — автопровижининг маппингов при создании
  реестра; Q8 (`Exploration`) — мультилотовые закупки (`lot_id` всегда `null`), влияет на D26/D27
  при появлении источника лотов. Q1–Q6 — `Resolved`.
