# P5 AUDIT-CAP remediation — execution design

## Owner boundary

После второго Audit P5 владелец запретил сдавать фазу с отклонениями или хвостами: остановить
AUDIT-CAP, записать owner decision, штатно переоткрыть P5 и довести её до полностью GREEN.
Третий Audit P5 запрещён; после GREEN P5.6 выполняется штатный close по D76. Historical
audit/journal/commits остаются append-only, generated cache не является target. RAG:—
(repo-grounded: owner directive 2026-08-11, `findings/P5-audit-cap-baseline-2026-08-11.md`).

## Single-slice topology

| Класс | Ремедиация P5.6 | Green evidence |
|:--|:--|:--|
| P5.5 out-of-Files | Новый item явно владеет двумя test paths, сохраняет current semantics и прогоняет общий P5 release carrier; затем P5.5 получает append-only correction | current P5.5 state `🟢`, `Known Deviations: —`, исторический event остаётся в journal |
| `--conflicts` standalone | Canon остаётся в `packages/skiller`; Task получает digest-locked packaged runtime projection, `_find_skill()` различает canonical source и projection | source + staged standalone plugin `--conflicts` дают 0/0; drift/missing projection fail closed |
| downstream RED/missing | Parser исполняет только один consumer-owned carrier, объявленный в Files; prose/path-less fragments не являются commands; затем P5.3–P5.5 records пересобираются на новом ancestor commit | current records только GREEN/UNKNOWN, старые RED findings сохраняются provenance |
| return qualification/runtime | Два factual evidence carriers квалифицируют resolved new fingerprints P5.2/P5.3; phase audit-attention→reopen не создаёт schema-invalid return | return-loop gate без missing evidence/schema error/owner gate |
| decision queue | Существующая request→claim→response цепочка не переписывается; projector подтверждает 0 unresolved | `_gates.md`: нет unresolved; queue/list/render checks green |
| closure | Все corrections идут через journal; state→bundle→decisions→status→closure; P5 закрывается без audit 3 | 0 ERROR / 0 WARN, byte-fresh views, closure `🟢 Done` и no deviations |

RAG:— (repo-grounded: baseline finding, D63, Task layout-v2 projection order).

## Canonical source/runtime design

`packages/skiller/skills/general/plan-protocol/` остаётся единственным handwritten canon.
`packages/task/runtime/plan-protocol/` — generated, digest-qualified runtime projection,
материализуемая и проверяемая штатным source generator вместе с provider projections. Task
conflict locator сначала использует canonical monorepo source, а в standalone package — только
проверенную local runtime projection. Нельзя искать произвольный newest cache, ослаблять missing/
digest mismatch до warning или копировать canon вручную. Tests обязаны построить isolated staged
Task package без `packages/skiller`, доказать GREEN на projection и fail-closed на missing/drift.
RAG:— (repo-grounded: Swissknifeman marketplace topology and current `_find_skill()`).

Downstream repair остаётся в том же Task source commit: executable admission выводится из exact
consumer `Files` и единственного item-owned carrier; explanatory Markdown, arbitrary inline code,
path-less commands и future carrier absence не запускаются. Existing consumer with produced carrier
по-прежнему реально исполняется, его non-zero остаётся RED. RAG:— (repo-grounded: D63,
`packages/task/lib/task/plan_downstream.py`).

Return projector в том же commit получает focused phase AUDIT-CAP regression: append-only
`plan-audit audit-attention` → `plan-design Pn` не может создавать schema-invalid fingerprint.
Исправление делается на owning projector/schema boundary, не переписыванием journal и не
искусственным qualification record для phase subject. Item-level P5.2/P5.3 fingerprints остаются
digest-qualified factual evidence. RAG:— (repo-grounded: current journal 217–218 and
`packages/task/lib/task/plan_returns.py`).

## Commit and rollback topology

1. В Swissknifeman отдельный source/runtime commit: locator + generated projection/generator +
   downstream admission + focused tests. До commit сверяется и сохраняется исходный dirty baseline;
   чужие paths не индексируются. Rollback — revert только этого commit; cache не затрагивается.
2. В Agelto отдельный product/evidence commit: два test paths, новый
   `docker/tests/p5.6-release-remediation.sh` и evidence report. Он pin-ит полный Swissknifeman SHA
   и полный Agelto candidate SHA. Rollback — revert этого product commit без plan history.
3. Только после обоих GREEN — append-only P5.2/P5.3 qualification и P5.5/P5.6 terminal events,
   новые downstream findings и generated views; затем отдельный bookkeeping commit и close P5.

## DoR verdict

Открытых product или authority решений нет: D76 фиксирует topology, anti-scope и отсутствие
третьего аудита. Scope cross-repository, но owner-explicit и обратим двумя отдельными commits;
маршрут `implementation/high`, `manual`, `Review=full`. Validation детерминированна и запрещает
GREEN при любом ERROR/WARN, deviation, unresolved queue request, stale view либо изменённом cache.
Уточняющих маркеров нет.
