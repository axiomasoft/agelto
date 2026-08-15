---
id: D88
date: 2026-08-15
status: accepted
item: P11
items: [P11]
supersedes: [D87]
superseded_by: null
---
## Решение

Владелец принимает независимый GREEN audit P11 как достаточный для закрытия фазы.
`phase-close: direct-green-validation` разрешает один штатный `task:plan-close` с
`owner_decision="D88"`; повторный Audit P11 не требуется.

Audit-fixer исправил найденные defects и на кандидате `cd3b534a` повторил executable local
`bash docker/tests/release-quality-contract.sh`: 13 fresh no-TIA прогонов по 1575 tests,
три fixed-seed проверки, coverage/performance gates и пять critical-file floors. После этого
изменились только audit report, journal, generated state/views и handoff. Нового product risk,
нового material deviation или нерассмотренного acceptance failure не появилось. RAG:—
(repo-grounded: `phases/P11/P11.md` → `Audit P11`,
`artifacts/P11-final-quality-report.md`, commits `1c50995a`–`99175e2`).

P11.6 сохраняет `🟠 Done with deviations`: live CI owner-directed не запускался. Это
ограничение уже названо и принято GREEN audit; оно остаётся обязательным terminal CI evidence
для P12 перед release approval и не объявляется зелёным этим решением.

## Почему

Текущий fingerprint включает generated audit bookkeeping. Поэтому он стал stale от работы
самого audit, а не от изменения P11 product boundary. Повторный full audit не добавит
независимого evidence и вновь создаст тот же self-induced stale cycle. Это подтверждённый
instance roadmap `2026.08.14-phase-close-impact-broker.md`, а не послабление quality gate.

## Alternatives

1. Повторить full Audit P11 — отклонено: duplicate expensive validation без нового material
   input или риска.
2. Переписать historical audit fingerprint — отклонено: нарушает append-only provenance.
3. Direct close по узкому owner override D88 с сохранением audit evidence и P12 CI gate — принято.

## Consequences

P11 закрывается только при шести terminal item states, свежих Task views/lint/citation checks
и phase handoff, буквально сохраняющем P11.6 deviation. Следующий execution root — P12.1 в
новой implementation/high session; P12 не запускается текущим close root. D88 supersedes D87
только в части successor P11 phase-close: CI ownership и item contracts D87 сохраняются.
