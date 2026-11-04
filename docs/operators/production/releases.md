---
audience: operator
type: runbook
status: living
---

# Release preparation

Эта страница описывает registry-oriented fixture contract. Текущий live путь без CI/registry —
[manual build/deploy](manual-deployment.md). Не считайте fixture receipt реальным deploy или
rollback. Host build разрешён только в выбранной manual ветке; runtime source bind mounts,
floating tags и destructive shutdown остаются запрещены.

The local release carrier fixes the order as preflight → verified backup → schema compatibility →
bounded Horizon drain → immutable app/Doctor pair promotion → readiness → canary. It records only
candidate/previous `sha256:` digests and stage IDs. Run:

```bash
bash docker/tests/release-recovery-contract.sh
```

The fixture proves backup before the first mutation, lock contention, bounded failure, reverse-order
rollback, resume and terminal idempotency. Unknown migration/schema compatibility stops before drain
or promotion. A failed readiness/canary reverses promotion and drain while retaining the verified
preflight/backup/compatibility prefix.

Current P12 never invokes live apply. The follow-up rehearsal owns registry authentication, queue
measurements, image promotion, migrations, HTTPS readiness/canary and the release approval. Use only
the canonical rows in текущий inventory команд; no host build, floating tag, implicit retry or blind
Compose shutdown is permitted.
