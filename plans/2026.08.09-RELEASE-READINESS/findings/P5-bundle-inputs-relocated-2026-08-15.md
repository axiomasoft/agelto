---
item: P5.3
date: 2026-08-15
kind: plan-input-defect
status: open
---

# P5.3/P5.5 bundle inputs were relocated by P11

## Observation

Layout-v2 bundle regeneration fails for P5.3 and P5.5 with
`unresolvable_entry: tests/Unit/Crawler/ProxyResolverTest.php`. P11 audit repair moved the
DB-backed test to `tests/Feature/Crawler/ProxyResolverTest.php`; the current product path is
tracked and the test behavior remains owned by the P11 isolation repair.

## Boundary

This is a historical P5 plan-input defect, not a P12 product defect. The terminal P5.3/P5.5
specifications record the path that existed during their execution and must not be hand-edited
or reconstructed from a later tree merely to make an all-bundle projection pass.

## Owner and next action

Owner: P5 plan maintenance. Resolve only through a reviewable plan-design amendment or a
Task-supported historical-input mechanism. Until then, P5.3/P5.5 join the already recorded
P10.1 historical-input exception; all other Layout-v2 bundles are regenerated and checked
without a v1 fallback.
