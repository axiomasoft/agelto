---
id: D42
date: 2026-08-09
status: accepted
item: P11.5
items: [P11.1, P11.4, P11.5, P11.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

GitHub Actions — thin carrier над terminal P1/P10 Make/Composer commands: read-only permissions by
default, no `pull_request_target`, third-party actions pinned to verified full SHA, private-package
credentials only in trusted jobs, and cache writes only from trusted triggers. Cache contains only
download stores keyed by OS/runtime and exact Composer/pnpm locks; env/secrets/auth, TIA/test state,
DB/media, `vendor` and `node_modules` executable trees are excluded. Required jobs cover local
quality/static, full no-TIA Unit+Feature/integration, D24 Playwright, immutable image/build,
dependency/image security and separate coverage. Hard quality floor: zero critical/high risk-matrix
gaps, global line coverage ≥70%, each configured critical file ≥55% and no regression from the
higher terminal P11.4 baseline. Performance budget is the greater of five-run median ×1.50 and
median +5s; flake gate repeats three fixed random seeds plus the selected D40 mode. RAG:✅ 2026-08-09
official GitHub secure-use/cache and Pest CLI/CI docs; numeric floors are internal release policy in
`research/13-P11-execution-design.md`.

## Почему

YAML duplication would drift from local gates, while broad caches and privileged triggers enlarge the
supply-chain/secret boundary. Percentage-only coverage rewards easy lines and misses release-critical
invariants; matrix plus non-regressing floors binds both semantics and breadth. Measured headroom makes
performance regression visible without treating ordinary runner noise as a failure.

## Consequences

Missing private dependency credentials or unavailable Actions execution is a blocked required check,
not an allowed skip. A higher measured P11.4 coverage becomes the floor even when it exceeds 70/55.
Performance-baseline refresh requires the same runner fingerprint, five clean runs, reviewable budget
diff and no simultaneous product change; generated TIA baseline remains a separate artifact, not cache.
