# Design audit #2 — 2026-09-10

**Вердикт: RED.** Implementation остаётся запрещена до узкого
`task:plan-design ... finish` repair и нового independent design audit.

## Границы и evidence

- Candidate fingerprint: `328a504932c90e4f7b9ecb8c261cf7672b2e9860e274f09a5689f0c5d6e1b53a`
  по 45 stable design carriers; audit-owned `journal/status/handoff/state/bundle` и этот
  finding в fingerprint не входят.
- Previous RED class materially improved: `plan-lint` и mandatory `state/decisions/status`
  checks GREEN; stdout-bundle собирается для 12 items. Оставшиеся 5 failures
  адресуют точные future phase-closure/owner/evidence carriers и допустимо
  отложены до их materialization.
- Sole Routing compiler: digest
  `ea4d7a3b342cd2a478c91f229cd692d5bb02233e35dcc02cde0e66c4bcbcbb40`; all 17
  items resolve to the declared route and execution mode.
- Provider route evidence: `gpt-5.6-sol/xhigh`, `source=provider-thread-state`, exact invocation
  and cwd matched.

## Findings

### DA2-F1 — Blocker — новые фазы не объявляют `Phase Assurance | v1`

`phases/P0/P0.md` … `phases/P4/P4.md` не содержат ни одного assurance block.
Это прямо нарушает текущий `task:plan-design` contract для новых фаз и
оставляет runtime без declared impact, integration seams, live/irreversible flag, audit
intent и rationale. Особенно критична P4 с live ЕИС и controlled-production.

Owning continuation: добавить typed assurance во все P0–P4 и сверить его
с phase handoff/audit policy.

### DA2-F2 — Major — P1.1 не потребляет terminal/audited P0

`phases/P0/P0.md:29` и `roadmap.md:15` требуют phase audit/close P0 до P1.1,
но `phases/P1/P1.1.md:11` пинит только `phases/P0/P0.1.state.md`. Этот state
существует уже в `Not started`; stdout-bundle P1.1 собрался сейчас с exit 0.
Таким образом, executable input не доказывает обязательный phase audit/close.

Owning continuation: заменить cross-phase input/read на
`phases/P0/P0.closed.md`; P1.1 bundle должен оставать deferred до terminal P0.

### DA2-F3 — Major — P4.2 launch carrier противоречит independence gate

`roadmap.md:23` и `phases/P4/P4.2.md:3,9,17` требуют fresh actor/context,
отличные от implementation/live executor. Но executable metadata в `roadmap.md:159-170`
объявляет `Context | continue-root`. Это не машинное доказательство fresh
independent context и может перенести live-author context в acceptance.

Owning continuation: P4.2 и его predecessor handoff должны явно выбирать
`cold-start-root` с новым provider session/context evidence.

### DA2-F4 — Major — новые Routing groups имеют legacy-unmarked semantics

Ни одна строка `plan.md` Routing не начинает `Почему` с `recommended:`
или `required:`. Sole compiler поэтому возвращает `recommended=false` для всех
12 compiled groups, то есть новый план неявно получает legacy exact-batch
behavior. Текущий `task:plan-design` contract требует `recommended:` для обычных
новых групп и `required:` для явно atomic/owner-mandated groups.

Owning continuation: классифицировать каждую группу; после изменения
Routing digest пересобрать P0.1 bundle/roadmap/handoff и вернуть plan на design audit.

## Audit disposition

Lifecycle advice: hard external-risk review, `audit_action=return-owning-continuation`,
`review=independent`, `validation=focused+affected-package+integration`,
`context=cold-start-root`. Audit-owned bookkeeping не изменяло application/tests/config.
Точный owning continuation: `design-finish: task:plan-design
2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION finish`.
