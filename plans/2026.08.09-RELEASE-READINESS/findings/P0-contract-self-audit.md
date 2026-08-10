---
item: P0.6
date: 2026-08-10
file: phases/P0/P0.6.md
rule_or_contract_locus: P0.6 Required Reads and Implementation Rules
defect_class: rule_conflict
gate: return-loop-v1
qualification: unqualifiable
missing_carrier_reason: fingerprint exists only after a terminal plan-design return event
---
# P0.6 contract self-audit

**Verdict:** RED

## Blocking finding

P0.6 requires the executor to prove first that P0.1–P0.5 are terminal and that their
Completion Notes name existing outputs. The terminal status is proven, but the byte-fresh
state carriers name no concrete output paths:

| Input | Terminal state | Completion Notes output reference |
|:--|:--|:--|
| P0.1 | 🟢 Done | none |
| P0.2 | 🟢 Done | none |
| P0.3 | 🟢 Done | item commit only; no output path |
| P0.4 | 🟢 Done | none |
| P0.5 | 🟢 Done | none |

The digest-qualified P0.6 bundle contains those state carriers and D4–D10/D14/D15/D56,
but it does not declare the findings produced by P0.1–P0.5. The required trace matrix cannot
therefore be built from the declared execution inputs. Reading repository files outside the
bundle would violate the Layout v2 read-path contract and make the freeze non-portable.

## Required remediation

`task:plan-design 2026.08.09-RELEASE-READINESS P0.6` must make the upstream outputs explicit:

1. add concrete output paths to durable upstream state evidence or declare the exact findings
   directly in P0.6 Inputs and Required Reads;
2. rebuild the P0.6 bundle and all affected derived views;
3. preserve the existing P0.1–P0.5 terminal facts and rerun P0.6 from its declared inputs.

## Refine rounds

No synthesis/refine round started because the input proof failed before contract authoring.
