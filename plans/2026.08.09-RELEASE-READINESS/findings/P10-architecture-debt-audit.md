# P10.3 — architecture, naming and debt audit

Audit date: 2026-08-13. Product evidence is anchored to
`d0b66df456b4406b1e7d751f4485c49dff7b966f`; it has no product/config difference from the
P10.1 immutable baseline `469acf640fc95944a3accc378445190dbca2a9ba`. This report is read-only:
it changes neither application bytes nor frozen D33/D82/D38 presentation and schema contracts.

## Method and boundary

The audit used four independent evidence classes. Counts are discovery inputs, not debt verdicts.

| Evidence class | Reproducible evidence | Result |
|:--|:--|:--|
| Static analysis | P10.1 configured PHPStan inventory (34 errors / 13 paths) and max/no-baseline inventory (1637 errors / 271 paths); `trait.unused` is one candidate in the living classification. | Candidate confirmation required caller and history evidence. |
| Git hotspot/history | `git log --format= --name-only --since=2026-07-01 -- app config routes tests | sort | uniq -c | sort -rn`; `config/ai.php` and `config/crawler.php` are the leading hot paths, not a reason by themselves to refactor them. `GracefullyStoppable` has exactly one introducing commit, `6d70a1f2`; `576794be` removed `section_number` from the repository writer. | One unused trait and one stale-guide statement confirmed. |
| Tests and coverage seams | `DomainLayoutTest`, `JobRetryContractGuardTest`, `EstimateSchemaTest`, `EstimatePersisterTest`, `GeminiProxyRoutingTest`, `ProxyBindingResolverTest` and `ProxyResolverTest` name the relevant behavior seams. | Guards preserve layer placement, job retry topology, schema absence and proxy policy boundaries. |
| Caller / route / config inventory | `rg` over `app`, `config`, `routes`, `database/migrations`, `tests` and installed AzGuard packages. On the anchor: no consumer of `GracefullyStoppable`; the `OutboundProxy` port has 44 source/test references, `ProxyBindingResolver`/`CrawlerProxySettingsResolver` have 27, and the two application routes are intentional HTTP entrypoints. | No duplicate-resolver, route, config, or one-implementation abstraction debt was proven. |

The source-package inventory included `vendor/axioma-studio/azguard-core` and
`vendor/axioma-studio/azguard-filament`. No source-package finding is recorded: any future one
must name its source repository and package owner rather than treating installed bytes as Agelto
code.

## Release-blocker boundary

P10.3 found **no new release blocker**. The only blocker rows handed to P10.5 remain P10.2-S01
(typed `ProxyResolver` policy bypass) and P10.2-S03 (MinIO credentials in child-process arguments);
they are frozen-contract/security work and are not reclassified by a duplication/debt audit.

## Debt register

### P10.3-D01 — Unused graceful-stop trait

- **Primary type:** code / dead code.
- **Location:** `app/Console/Concerns/GracefullyStoppable.php`.
- **Evidence:** static-analysis classification lists `trait.unused`; caller inventory finds the
  symbol only in its own declaration and illustrative docblock; `git log --follow` shows its single
  introducing commit `6d70a1f2`; no command implements `SignalableCommandInterface` or calls its
  signal methods; the current console route inventory has no consumer.
- **Impact:** low incident risk, low delivery drag, medium false-confidence risk: a documented
  graceful-shutdown template looks deployed while Horizon/commands do not exercise it.
- **Effort:** XS.
- **Owner / trigger:** P10.5; before the next release candidate, either delete the unused trait or
  add a real `SignalableCommandInterface` consumer with an integration seam. Do not preserve it as
  speculative generality.
- **Disposition:** deferred to the P10.5 remediation matrix; not a release blocker.
- **Regression seam:** deletion is verified by the item-owned contract's zero-consumer inventory;
  adoption instead requires an explicit command-level SIGTERM test. Existing
  `JobRetryContractGuardTest` remains a separate retry-topology guard.

### P10.3-D02 — Static-analysis guide retains a resolved live-defect claim

- **Primary type:** process / documentation debt.
- **Location:** `docs/03_Dev/static-analysis.md`, section `Найденный вживую дефект`.
- **Evidence:** the guide says `EstimateStoreRepository::findOrCreateSection()` still reads and
  writes `Section::$section_number`. Current caller inventory finds `section_number` only in the
  migration comment and schema assertion; the writer accepts only `position`; the schema test
  asserts the column is absent. Git commit `576794be` removed the parameter, write and comparison
  and its commit message records removal of the orphaned PHPStan baseline entry.
- **Impact:** low incident risk, medium audit/rework cost: a future reviewer can open an unnecessary
  P10.5 repair or misclassify the PHPStan baseline.
- **Effort:** XS.
- **Owner / trigger:** P10.6; reconcile living static-analysis documentation before the final P10
  documentation gate, retaining the historical P6.3 provenance without claiming that the defect is
  still live.
- **Disposition:** deferred to P10.6; not a release blocker and not a product-code change.
- **Regression seam:** `tests/Feature/Procurement/Estimate/EstimateSchemaTest.php` preserves the
  absence of `section_number`; the P10.3 contract verifies the writer has no reference.

## Accepted / false-positive ledger

| Signal | Proof of non-debt | Disposition |
|:--|:--|:--|
| Files over 500 LOC (`ExtractionOrchestrator`, `EvalMetrics`, `GsfxGoldMapper`, `GsfxReader`, `EvalRunComparator`) | File size is a signal only. The first has guarded lifecycle and retry seams; the remaining files are domain-specific processing surfaces with named tests. No duplicated behavior or removal payoff was proven. | accepted; re-open only with a concrete repeated change that crosses a behavior seam. |
| One implementation of `OutboundProxy` | `PrismGeminiProvider` consumes an Llm-owned port, `PoolOutboundProxy` is a Procurement adapter and two tests provide doubles. Removing it would violate the provider-agnostic Llm boundary and worsen D29/P10.2-S01 remediation. | false-positive. |
| `CrawlerProxySettingsResolver` and `ProxyBindingResolver` wrappers | Each owns a distinct policy: refreshed default setting versus crawler/profile binding. They have multiple application consumers and focused proxy tests. | false-positive. |
| P10.1/P6.3 historical `section_number` defect | It was real before `576794be`, but source, writer call sites and schema test on the anchor disprove it as a current product defect. | false-positive for product remediation; D02 records only the stale guide. |
| Generic modern-PHP candidates (`non-final`, constructor, named-argument and docblock counts) | D39 explicitly forbids blanket finalization/named-argument rewrites. No caller, history and behavior evidence establishes a focused payoff. | accepted; no bulk cleanup. |

## P10.5 handoff

P10.5 receives two bounded vertical seams from this audit:

1. P10.3-D01: choose deletion or a real command consumer; do not leave an untested template.
2. P10.3-D02: do not modify product code; update the living guide in P10.6 with the preserved
   `576794be` resolution evidence.

This audit adds no frozen-contract contradiction. P10.5 must still resolve or explicitly escalate
P10.2-S01 and P10.2-S03, then apply the P10.2-S02 admission-policy decision.
