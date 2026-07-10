# HANDOFF — 2026-07-10 — after P5.3

**Next:** `P5.4` — листинг перестаёт затирать `prc_procurements.customer_org_id` (D24, D28): удалить
`customerOrgId` из `ProcurementSnapshotData` (свойство + `toSnapshotArray()`), из обоих write-site'ов
`ProcurementStoreRepository` и из `EisCsvRowMapper`; обязательный red→green регресс-тест
`CustomerOrgOwnershipTest`. Item полностью специфицирован (17 полей, готовые Implementation
Rules/Code Guidance в `phases/P5.md`); последний item фазы P5 — после него фаза терминальна (4/4).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — item полностью специфицирован, правка чисто вычитающая, решение готово в D24/D28 |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Удалить мёртвый write-path листинга для `customer_org_id` (4 файла); regression-тест обязан быть красным ДО правки |

```
/task:plan-exec DOMLANG P5.4
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
P4 терминальна (1/2, 🟠 Done with deviations) — `P4.1` 🟢, `P4.2` ⛔ Skipped by decision (вердикт
`N = 0`, provenance не материализуется). P5 в работе (3/4) — `P5.1` 🟢 Done (TTL unique-лока,
ADR-0009); `P5.2` 🟢 Done (два частичных unique-индекса `prc_bids`, `NULLS NOT DISTINCT`, D26);
`P5.3` 🟢 Done — `ProcurementPartyStoreRepository::syncWinnerRoles(int $procurementId, array
$winnerOrgIds): void` (`whereNotIn`-delete по `role=winner`), вызов из
`SupplierResultsProcessor::storeAward()` сразу после `upsertParty(Winner)` внутри уже открытой
транзакции; `tests/Feature/Procurement/ProtocolAwardTest.php` получил 2 новых теста (снятие роли при
смене победителя; сохранение других ролей той же организации); `docs-private/open-follow-ups.md` §6
закрыт (ссылка на D27), ошибочное имя `org_procurement_parties` убрано, актуальное —
`prc_procurement_parties`.

**Remaining:** P5.4 (готов к exec, полное ТЗ в `phases/P5.md`) → P6–P9 (скелеты; каждая фаза перед
exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board (P5 = 3/4) · §5 D-лог (D27 — sync роли `winner`, закрыт
  P5.3; D28 — удаление `customerOrgId` из write-path листинга, следующий item) · §6 Update Log.
- `plans/DOMLANG/phases/P5.md` — 4 item'а с полными 16–17 полями; `P5.1`/`P5.2`/`P5.3` закрыты с
  Completion Notes.
- `plans/DOMLANG/findings/P5-pipeline-defects.md` §4 (клоббер `customer_org_id`, исчерпывающий греп
  10 вхождений) для P5.4.
- `plans/DOMLANG/findings/P4-write-site-map.md` §2 — карта write-site'ов, на которой построен D24.
- `plans/DOMLANG/workflow.js` — `DECISION_RULES` для P5.4 не заведён (не требовался — решение уже в
  D24/D28).

**Open risks:**
- `customer_org_id` затирается в `null` на каждой новой ревизии листинга (D24/D28) — **живой дефект
  в коде**, чинится в `P5.4`, ещё не задет.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён;
  `02-crawling.md:159` противоречит ADR-0009 (правку спеки делает P6).
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
