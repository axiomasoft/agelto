---
audience: dev
type: reference
status: frozen
---

# ProcurementSucceeded v1.0.0

## Event

`App\Events\Procurement\ProcurementSucceeded` signals that one procurement first reached a
positive outcome: its canonical status is positive terminal, or an award with `winner_org_id`
exists.

## Payload

| Field | Type | Meaning |
|:--|:--|:--|
| `procurementId` | int | Procurement primary key. |
| `registryId` | int | Registry primary key. |
| `registryNumber` | string | Registry number from the procurement head. |
| `canonicalStatus` | string | Canonical status at emission. |
| `reason` | `status`\|`award` | Fact that first satisfied the outcome rule. |
| `succeededAt` | Carbon | Timestamp of the atomic outcome claim. |
| `hasEstimate` | bool | Whether recognized estimates existed at emission. |
| `estimatesCount` | int | Count of recognized estimates at emission. |
| `winnerOrgId` | int\|null | Identified winner, if known. |
| `awardId` | int\|null | Award that supplied the winner, if applicable. |

## Guarantees and limits

- `succeeded_at` is claimed atomically, so the event is emitted at most once per procurement.
- Dispatch happens only after the surrounding database transaction commits; rollback emits nothing.
- Estimate fields are a snapshot, not a completeness promise: estimates may be recognized later.
- This contract declares no listener. Supplier notification belongs to a future plan.
