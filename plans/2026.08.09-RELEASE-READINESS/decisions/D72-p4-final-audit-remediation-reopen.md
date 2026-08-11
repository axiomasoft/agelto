---
id: D72
date: 2026-08-11
status: accepted
item: P4.6
items: [P4.1, P4.2, P4.3, P4.4, P4.5, P4.6]
supersedes: []
superseded_by: null
---
## Решение

RED-аудит P4, заход 1, закрывается ровно одним remediation-item P4.6. Реопен повышает
`migration-version` `0.1.5` → `0.1.6`, добавляет nonterminal P4.6, фиксирует причину этой
D-записью и инвалидирует `## Phase Handoff` маркером `> УСТАРЕЛ — фаза реопенена D72`.
Layout v2 Status Board до первого execution event P4.6 показывает nonterminal `⬜ Not started`,
а не общий reopen-канон `🟡 In progress`: projector выводит фазовый статус из item states и не
fabricates execution event из phase journal subject. Append-only `plan-design` event фиксирует
сам reopen, generated status/closure views не правятся руками. Это известное protocol deviation/
PROMOTE-CANDIDATE, а не скрытый пропуск шага. RAG:— (repo-grounded: `phases/P4/P4.md` →
`## Audit P4 — 2026-08-11`, D70 precedent, Task Layout v2 projection contract).

Active SVG полностью исключается из private-documentation artifact contract: `.svg` и
`image/svg+xml` удаляются из build/manifest/PHP closed allowlists, hostile SVG обязан падать на
build gate, а forged manifest/direct URL не возвращает bytes. CSP-only/sanitizer/attachment не
принимаются как параллельная security policy. RAG:— (repo-grounded: audit F1 and current
`scripts/documentation/verify-dist.mjs`/`PrivateDocumentationAsset.php`).

P4.6 становится единственным terminal acceptance carrier P4: clean detached candidate сам
materializes pinned private vendor через repository-owned sync, создаёт ephemeral candidate-local
admin credentials, доказывает exact manifest body hash/MIME/все headers и direct asset denial
после logout/revoke, фиксирует git SHA/image digest и только после GREEN append-only reconciles
P4.1/P4.2/P4.5. P4.2 получает `🟠 Done with deviations`. После GREEN P4.6 выполняется штатный
close P4 без нового design/audit цикла; дефект frozen acceptance исправляется внутри P4.6 до
GREEN, а не переносится в повторный reopen. RAG:— (repo-grounded: owner direction 2026-08-11,
`research/20-P4-audit-remediation.md`).

Owner directive закрыть P4 в текущей сессии также разрешает две protocol-коллизии без расширения
product scope. `phase_close_gate` не создаёт новый GREEN fingerprint после owner-waived audit,
поэтому historical RED не блокирует штатный close по принятому D71 precedent.
`downstream-gate-v1` для P4.6 механически исполняет prose-токены неисполненных P5.5/P11.4
(`--no-tia`, `Http::preventStrayRequests` и аналоги) вместо отсутствующих будущих item-owned
carriers. Его RED records сохраняются append-only как protocol evidence, но не выдаются за product
regression и не разрешают менять чужие фазы или Swissknifeman runtime. Обязательный P4.6 composite
carrier, strict citations и Layout v2 checks остаются без waiver и должны быть GREEN. RAG:—
(repo-grounded: P4.6 downstream records, D63/D71, owner direction 2026-08-11).

## Почему

F1–F4 образуют один security/reproducibility hotspot: один candidate tree должен одновременно
быть допустимым artifact set, clean-buildable immutable image и реально пройденным browser
surface. F5–F8 разрешены только после этого anchor и являются append-only closure evidence.
Разделение создало бы промежуточный ложный GREEN и снова позволило бы phase closure ссылаться на
не тот tree/image. RAG:— (repo-grounded: P4 audit F1–F8).

## Alternatives

1. Оставить SVG и добавить CSP. Отклонено: direct same-origin active document сохраняет вторую
   policy surface и не нужен текущему VitePress output.
2. Санитизировать SVG. Отклонено: вводит новый parser/security contract вне D26/D27.
3. Переоткрыть P4.1–P4.5 и переписать их specs/state. Отклонено: ломает append-only provenance.
4. Разнести fixes и evidence по нескольким items. Отклонено: clean build/browser/image evidence
   обязано относиться к одному committed candidate.
5. Один P4.6 с fail-closed SVG rejection, self-contained candidate, GREEN composite carrier и
   append-only corrections. Выбрано.

## Consequences

План содержит 80 items. P4.6 исполняется `implementation/high`, `manual`, `Review=full`; текущая
frontier/high сессия является допустимым route overshoot и обязана явно записать отклонение.
SVG больше не является поддерживаемым documentation artifact; если будущий generator сделает его
обязательным, потребуется новое evidence-backed решение, а не расширение allowlist. Исторические
commits/audit/journal остаются неизменными. P5 не стартует до terminal P4.6 и штатного close P4.
Downstream false-positive и audit-fingerprint waiver печатаются в P4.6/phase Known Deviations как
process deviations; они не снимают ни одного acceptance-критерия P4.6 и не изменяют P5/P11.
RAG:— (repo-grounded: `phases/P4/P4.6.md`, `roadmap.md`, owner direction 2026-08-11).
