---
id: D83
date: 2026-08-13
status: superseded
item: finish
items: [finish]
supersedes: [D52, D55]
superseded_by: D84
---
## Решение

Явный owner invocation `$ task:plan-design 2026.08.09-RELEASE-READINESS finish` запускает новый
полный cross-phase reconcile current Layout v2 topology: 13 фаз / 84 items. D83 supersedes D52 как
machine-current finish carrier, потому что D52 доказывал только 75-item topology, и supersedes D55
только в части исторического waiver следующего design-audit: после текущего finish обязателен
independent fresh-root `task:plan-audit 2026.08.09-RELEASE-READINESS design`. Terminal product
states, закрытые phase audits и remediation decisions D62–D82 остаются неизменными. RAG:—
(repo-grounded: `brief/01-refinements.md`, current `phases/` inventory, Routing and `status.md`).

## Почему

После D52 план вырос с 75 до 84 specs: добавлены P0.7/P1.6/P1.7/P2.7/P4.6/P5.6/P6.7/P8.6/P8.7.
Meta всё ещё называла D52 current carrier, а roadmap смешивал историческую карту со свежими
terminal states. Холодный consumer не мог доказать, что все 84 specs, межфазные consumers,
Routing, bundles и execution map прошли единый finish после последнего изменения topology.

## Alternatives

1. Сохранить D52 current и считать девять remediation-items вне topology — отклонено: каждый имеет
   spec, state, bundle, Routing row и status projection.
2. Распространить D55 на новую topology без аудита — отклонено: D55 принимал конкретный
   post-remediation 75-item design после трёх исторических audit rounds.
3. Additive D83, полный 84-item reconcile и новый independent design audit — принято.

## Consequences

**Consequences:** migration-version повышается `0.1.11` → `0.1.12`; legacy пустой Batch marker
`—` нормализуется в `solo`, bundles/views пересобираются штатными owners, roadmap отражает current
terminality, а Phase Handoff P3/P5/P9–P12 больше не объявляют superseded D52/D55 current gate.
Следующий design-root-terminal handoff ведёт на `frontier/xhigh` audit. До его вердикта выполнение
P9.4 и последующих items приостановлено; product/history carriers не меняются.
