---
id: D79
date: 2026-08-12
status: accepted
item: P6.7
items: [P6.1, P6.2, P6.3, P6.4, P6.5, P6.6, P6.7]
supersedes: []
superseded_by: null
---
## Решение

AUDIT-CAP P6 закрывается bounded recovery без изменения product semantics D30/D31 и без
третьего Audit P6. Владелец разрешает повторить исходные Validation для P6.1/P6.3/P6.5,
P6.6 и P6.7 только в fresh native roots с provider-attested фактическими маршрутами,
сохранить historical route undershoot в Known Deviations и записать для новых terminal
attempts только правдивый `write-site: pre_mutation: allow · pre_final: allow`.
Self-attestation, fabricated `allow` и переписывание исторического route запрещены.
RAG:— (repo-grounded: `phases/P6/P6.md` → `Audit P6 — 2026-08-12 (заход 2)`, owner
directive 2026-08-12 and Task route-evidence contract).

Разрешение ограничено route-attested recovery, точечной починкой stale F3 citation anchor,
штатной регенерацией Layout v2 state/bundle/decisions/status/closure views и обновлением stale
Phase Handoff/root handoff только во время close. Product-код меняется лишь при доказанном
дефекте исходной Validation; D30/D31, historical audit/journal/commits и named deviations не
скрываются и не переписываются. RAG:— (repo-grounded: P6 Audit F1–F4 and owner directive
2026-08-12).

После GREEN `docker/tests/p6.7-release-remediation.sh`, focused/full no-TIA suites, Task lint,
strict citations, byte-fresh projections и `git diff --check`
фаза закрывается штатным `task:plan-close`. Closing root обязан вызвать `phase_close_gate` с
`owner_decision="D79"`;
любая невалидная route capture, RED Validation или новый реальный product blocker остаётся
fail-closed и чинится continue-first только в P6 scope. RAG:— (repo-grounded: Task
`phase_close_gate` AUDIT-CAP direct-close contract and owner directive 2026-08-12).

## Почему

Финальный Audit P6 доказал зелёный product slice и одновременно обнаружил только route/write-site
bookkeeping, один stale citation anchor и stale handoff. Fresh route-attested recovery attempts
дают новый публично проверяемый terminal факт, не превращая historical undershoot в разрешённый
маршрут; D79 передаёт ровно этот owner outcome единственному phase-close policy seam.
RAG:— (repo-grounded: `phases/P6/P6.md` → Audit P6 findings F1–F4).

## Alternatives

1. Провести третий Audit P6. Отклонено владельцем: audit budget исчерпан.
2. Переписать historical route/journal/state как `allow`. Отклонено: это уничтожило бы provenance.
3. Менять D30/D31 или product-код без воспроизводимого дефекта. Отклонено: product semantics frozen.
4. Выполнить bounded fresh-route validation, точечную F3 citation repair и direct close по D79.
   Выбрано.

## Consequences

D79 не добавляет item и не расширяет product scope. P6.1–P6.7 сохраняют исторические статусы и
named deviations, но новые recovery attempts получают собственные provider-attested route и
`allow/allow` write-site evidence. Stale handoff переписывается только штатным phase close;
closing commit остаётся одним pathspec-only `docs(plan)` commit по протоколу.
RAG:— (repo-grounded: owner directive 2026-08-12 and Layout v2 close protocol).
