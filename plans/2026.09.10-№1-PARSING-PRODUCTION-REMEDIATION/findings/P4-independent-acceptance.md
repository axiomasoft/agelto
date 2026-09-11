# P4.2 — factual independent acceptance evidence

Дата: 2026-09-11
Вердикт продукта: **GREEN**
Вердикт item: **GREEN, terminal close allowed**
Machine artifact: `artifacts/P4-independent-acceptance.json`
Artifact SHA-256: `6586eab263407e0624ecaf99c184d7b64d4cf715022ccb74242e66f0d6b95095`

## Независимость и границы

- Финальный replay выполнил свежий read-only actor `/root/p4_2_final_replay`, thread
  `01a090bf-3c54-7c10-be56-b7ed21a1fc83`; он не совпадает с P4.1 и предыдущими P4.2 sessions.
- Replay не выполнял live/outbound-запросов и не менял файловую систему проекта.
- P0–P3 closures, terminal P4.1 state и P4.1 A1/B2 evidence chain повторно hash-bound.
- Owner validation repair ограничен `P4.2.md`, `config/doctor.php` и
  `docker/tests/pipeline-worker-recovery-contract.sh`; assertions, lease predicates и product
  semantics не менялись.

## Матрица A/NEW/B

| ID | Verdict | Evidence |
|:--|:--|:--|
| A1, NEW-1 | GREEN | FreshRun 13/59; dirty `stop_reason` сохраняется fenced cursor write, continuation заменяет checkpoint reason |
| A2, A3, B1 | GREEN | recovery/snapshot/projection 58/249 |
| A4, A5 | GREEN | pipeline recovery/retry/download 35/118 |
| A6, NEW-2 | GREEN | storage fail-closed 23/100 |
| A7, A8, A9 | GREEN | timeout/lease/partition 25/134 и exact real-worker recovery GREEN |
| B2 | GREEN conditional | D8 `insufficient_evidence`; bounded revalidation 12/39, universal uid claim отсутствует |

## Финальная validation

- `make test-preflight` — GREEN.
- Self-contained `migrate:fresh --seed` — GREEN.
- Exact `bash docker/tests/pipeline-worker-recovery-contract.sh` — GREEN: 5/49 + 3/11;
  SIGTERM завершён, SIGKILL прерван и восстановлен.
- Targeted broad — **868 passed / 3174 assertions**.
- Full suite — **1639 passed / 7441 assertions**.
- Pint — **1547 files, GREEN**; PHPStan — **718 files, GREEN**.
- TLS guard, JSON/schema/SHA checks и `git diff --check` — GREEN.
- Task `pre_final` — `allow`.

Все прежние validation findings (`VALIDATION-SEED`, `VALIDATION-WORKER-CARRIER`, `QUALITY-PINT`,
`PLAN-EXACT-REPLAY`, `PLAN-PUBLIC-ACCEPTANCE`) закрыты GREEN. Новых plan-attributable failures не
обнаружено. P4.2 допускается к terminal close; следующий P4.3 остаётся за отдельным durable owner
authorization gate перед любым controlled-production traffic.
