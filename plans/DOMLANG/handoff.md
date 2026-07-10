# HANDOFF — 2026-07-10 — after P5.2

**Next:** `P5.3` — синхронизация роли `winner` в `prc_procurement_parties` с
`prc_awards.winner_org_id`: `ProcurementPartyStoreRepository::syncWinnerRoles()`, вызов из
`SupplierResultsProcessor::storeAward()` внутри уже открытой транзакции, + закрытие §6
`open-follow-ups.md`. Item полностью специфицирован (17 полей, готовая сигнатура и порядок вызова
в `phases/P5.md`).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — item полностью специфицирован, решение и сигнатура готовы в D27 |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | `syncWinnerRoles(int $procurementId, array $winnerOrgIds): void` (whereNotIn-delete по `role=winner`), вызов после `upsertParty(Winner)` в той же транзакции; 2 новых теста в `ProtocolAwardTest.php` |

```
/task:plan-exec DOMLANG P5.3
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
P4 терминальна (1/2, 🟠 Done with deviations) — `P4.1` 🟢, `P4.2` ⛔ Skipped by decision (вердикт
`N = 0`, provenance не материализуется). P5 в работе (2/4) — `P5.1` 🟢 Done (TTL unique-лока,
ADR-0009); `P5.2` 🟢 Done — миграция `…100021` получила два частичных unique-индекса
(`prc_bids_identified_participant_unique`/`prc_bids_anonymous_participant_unique`, `NULLS NOT
DISTINCT`, D26), `tests/Feature/Procurement/BidNaturalKeyTest.php` (4 теста), `crawler-gotchas.md`
§3 актуализирован (`prc_bids` выведен из списка таблиц без DB-подкрепления).

**Remaining:** P5.3–P5.4 (готовы к exec, полные ТЗ в `phases/P5.md`) → P6–P9 (скелеты; каждая фаза
перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board · §5 D-лог (D26 — два частичных unique-индекса `prc_bids`,
  закрыт P5.2; D27 — sync роли `winner`, следующий item; D28 — удаление `customerOrgId` из
  write-path листинга) · §6 Update Log.
- `plans/DOMLANG/phases/P5.md` — 4 item'а с полными 16–17 полями; `P5.1`/`P5.2` закрыты с
  Completion Notes.
- `plans/DOMLANG/findings/P5-pipeline-defects.md` — карта всех четырёх дефектов, §3 (роль `winner`)
  для P5.3.
- `docs/03_Dev/adr/0009-job-owns-document-transition.md` — принятое решение P5.1.
- `plans/DOMLANG/workflow.js` — `DECISION_RULES['P5.1']` (исполнено), `DECISION_RULES` для
  остальных items P5 не заведены (не требовались — решения уже в D26–D28).

**Open risks:**
- `customer_org_id` затирается в `null` на каждой новой ревизии листинга (D24/D28) — **живой дефект
  в коде**, чинится в `P5.4`, ещё не задет.
- Роль `winner` не снимается при пересмотре итогов (D27) — **живой дефект**, чинится в `P5.3`.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён;
  `02-crawling.md:159` противоречит ADR-0009 (правку спеки делает P6).
- `prc_protocols.source_number` (18 вхождений, 8 файлов) намеренно не переименован (D20).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список; `.env.example`/директория репо на хосте
  закрыты Read/Edit-permission харнесса (не docker) — правки через `docker exec -u root agelto_app
  php -r '...'` + `chown 1000:1000` на изменённый файл.
- Модели `Bid`/`Protocol` не имеют Eloquent relations (`procurement()` и т.п.) — фабрики/тесты
  связывают через явные FK-поля, `->for()` в тестах на этих моделях недоступен (обнаружено в P5.2).

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в
  shell); правка `.env.example` — через `docker exec -u root` (Read/Edit-тулы харнесса блокируют
  путь напрямую), с обязательным `chown 1000:1000` после записи.
- Deferred: unique-индексы под естественные ключи `prc_financing`/`prc_items`/`prc_protocols`/
  `prc_awards` — тот же класс, что D26, но вне скоупа P5 (Scope Excluded фазы).
- Open questions (`open-questions.md`): Q7 (`Exploration`) — автопровижининг маппингов при создании
  реестра; Q8 (`Exploration`) — мультилотовые закупки (`lot_id` всегда `null`), влияет на D26/D27
  при появлении источника лотов. Q1–Q6 — `Resolved`.
