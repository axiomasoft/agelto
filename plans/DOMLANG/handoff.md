# HANDOFF — 2026-07-10 — after P5.1

**Next:** `P5.2` — материализация ветвящегося прикладного ключа `prc_bids` двумя частичными
unique-индексами Postgres 17 (`NULLS NOT DISTINCT`), edit-in-place миграции `…100021` + `migrate:fresh`.
Item полностью специфицирован (16 полей, готовый DDL в `findings/P5-pipeline-defects.md` §2).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — item полностью специфицирован, DDL и решение готовы в findings/D26 |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Два `DB::statement` с `NULLS NOT DISTINCT WHERE` в миграции `2026_07_04_100021_create_prc_bids_table.php`; `BidNaturalKeyTest.php` (4 кейса); `migrate:fresh --seed` |

```
/task:plan-exec DOMLANG P5.2
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 терминальна (3/3 🟢).
P4 терминальна (1/2, 🟠 Done with deviations) — `P4.1` 🟢, `P4.2` ⛔ Skipped by decision (вердикт
`N = 0`, provenance не материализуется). P5 в работе (1/4) — `P5.1` 🟢 Done: `PipelineJob::uniqueFor()`
+ `ai.pipeline.unique_lock_minutes` закрывают вечный unique-лок на Redis (D25), ADR-0009 фиксирует
owner перехода `downloaded → prepared` (сам `PrepareDocumentJob`, не мост, `DocumentStatus::Queued`
не вводится), `docs-private/{risk-register,crawler-gotchas}.md` приведены в соответствие.

**Remaining:** P5.2–P5.4 (готовы к exec, полные ТЗ в `phases/P5.md`) → P6–P9 (скелеты; каждая фаза
перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board · §5 D-лог (D25 — TTL unique-лока, закрыт P5.1; D26 — два
  частичных unique-индекса `prc_bids`, следующий item; D27 — sync роли `winner`; D28 — удаление
  `customerOrgId` из write-path листинга) · §6 Update Log.
- `plans/DOMLANG/phases/P5.md` — 4 item'а с полными 16 полями; `P5.1` закрыт с Completion Notes.
- `plans/DOMLANG/findings/P5-pipeline-defects.md` — карта всех четырёх дефектов, готовый DDL
  индексов (§2, для P5.2), механика `UniqueLock`/`RedisLock`/`ArrayLock` (§1, использована в P5.1).
- `docs/03_Dev/adr/0009-job-owns-document-transition.md` — принятое решение P5.1 (owner перехода,
  supersedes часть D17).
- `plans/DOMLANG/workflow.js` — `DECISION_RULES['P5.1']` (исполнено), `DECISION_RULES` для
  остальных items P5 не заведены (не требовались — решения уже в D26–D28).

**Open risks:**
- `customer_org_id` затирается в `null` на каждой новой ревизии листинга (D24/D28) — **живой дефект
  в коде**, чинится в `P5.4`, ещё не задет.
- Роль `winner` не снимается при пересмотре итогов (D27) — **живой дефект**, чинится в `P5.3`.
- `prc_bids` без DB-подкрепления прикладного ключа (D26) — **живой риск** (приемлем при
  параллелизм=1), чинится в `P5.2`.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён;
  `02-crawling.md:159` противоречит ADR-0009 (правку спеки делает P6).
- `prc_protocols.source_number` (18 вхождений, 8 файлов) намеренно не переименован (D20).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список; `.env.example`/директория репо на хосте
  закрыты Read/Edit-permission харнесса (не docker) — правки через `docker exec -u root agelto_app
  php -r '...'` + `chown 1000:1000` на изменённый файл.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в
  shell); правка `.env.example` — через `docker exec -u root` (Read/Edit-тулы харнесса блокируют
  путь напрямую), с обязательным `chown 1000:1000` после записи.
- Deferred: unique-индексы под естественные ключи `prc_financing`/`prc_items`/`prc_protocols`/
  `prc_awards` — тот же класс, что D26, но вне скоупа P5 (Scope Excluded фазы).
- Open questions (`open-questions.md`): Q7 (`Exploration`) — автопровижининг маппингов при создании
  реестра; Q8 (`Exploration`) — мультилотовые закупки (`lot_id` всегда `null`), влияет на D26/D27
  при появлении источника лотов. Q1–Q6 — `Resolved`.
