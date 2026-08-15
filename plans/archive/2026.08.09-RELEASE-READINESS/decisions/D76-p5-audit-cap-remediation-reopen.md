---
id: D76
date: 2026-08-11
status: accepted
item: P5.6
items: [P5.1, P5.2, P5.3, P5.4, P5.5, P5.6]
supersedes: []
superseded_by: null
---
## Решение

Владелец отклоняет обе AUDIT-CAP развилки второго Audit P5: фазу нельзя закрыть с Task-runtime
deviation и нельзя оставить остановленной. P5 штатно переоткрывается одним последним remediation-
item P5.6; `migration-version` повышается `0.1.7` → `0.1.8`, Phase Handoff инвалидируется
маркером `> УСТАРЕЛ — фаза реопенена D76`, generated status выводит nonterminal P5.6, а
historical audit/journal/commits не переписываются. RAG:— (repo-grounded: Audit P5 round 2,
owner directive and `research/21-P5-audit-cap-remediation.md`).

P5.6 обязан закрыть без waiver единый acceptance set: принять под своим Files два бывших
out-of-Files P5.5 test paths и получить current GREEN P5.5 correction; исправить `plan-lint
--conflicts` в canonical Swissknifeman source через digest-locked packaged runtime projection,
никогда не правя generated Codex cache; исправить downstream admission до D63 carrier-only
семантики; материализовать P5.2/P5.3 return qualification и починить phase AUDIT-CAP return
projection без journal rewrite; получить 0 unresolved decision gates;
пересобрать current downstream records и все Layout v2 views. RAG:— (repo-grounded:
`findings/P5-audit-cap-baseline-2026-08-11.md`).

После полностью GREEN P5.6 фаза закрывается штатным `task:plan-close` в той же fresh root без
третьего Audit P5. Historical ATTENTION остаётся provenance, но не является действующим
deviation: D76 owner-authorizes exact remediation/close path. Любой новый product/frozen-contract
дефект, cache-only workaround, ERROR/WARN, RED/UNKNOWN там, где contract требует GREEN, либо
чужой dirty diff запрещает close и чинится внутри P5.6; хвост в Pending Work не допускается.
RAG:— (repo-grounded: owner directive 2026-08-11 and plan-protocol append-only close semantics).

## Почему

Out-of-Files correction, conflict scanner и downstream/return projections сходятся в одном
финальном release carrier: только один pair of committed source/product anchors позволяет честно
пересобрать records и доказать отсутствие deviations. Разделение оставило бы промежуточный
ложный GREEN или cross-session tail; переписывание P5.5 spec/history скрыло бы факт нарушения.

## Alternatives

1. Закрыть P5 оранжевой. Отклонено прямой директивой владельца.
2. Править `~/.codex/plugins/cache/**`. Отклонено: generated cache не source и невоспроизводим.
3. Переписать P5.5 Files задним числом или удалить journal event. Отклонено: ломает provenance.
4. Создать третий Audit P5. Отклонено прямой директивой владельца.
5. Один P5.6 с двумя atomic repository commits, общим GREEN carrier и direct close. Выбрано.

## Consequences

План содержит 81 item. P5.6 исполняется `implementation/high`, `manual`, `Review=full` и имеет
явно разрешённый cross-repository Files scope только для named Swissknifeman Task source/runtime
paths. Оба исходных dirty trees сохраняются; commits строятся pathspec-only. Canon остаётся в
Skiller, Task runtime projection generated/digest-locked, cache не меняется. P6 не стартует до
terminal GREEN P5.6, fresh closure P5 и отдельного bookkeeping commit.
