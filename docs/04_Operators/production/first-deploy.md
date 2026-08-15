---
audience: operator
type: runbook
status: living
---

# First production deploy preparation

`docker/scripts/first-production-deploy.sh check` prints the canonical stage order without mutating
the host: preflight, data services, migrations, base seed, create-only initial admin, workers,
assets/private docs, readiness, Doctor and authorized browser proof. The hermetic fixture carrier is:

```bash
bash docker/tests/first-production-deploy-contract.sh
```

The tracked state schema contains only the candidate SHA, production-profile SHA and completed stage
IDs. A failure persists the successful prefix; rerunning the same candidate/profile resumes at the
failed stage, while a different identity fails closed. A terminal rerun is byte-stable and performs
no adapter calls.

Current P12 does not implement or invoke live `apply`. Registry authentication, image pull,
file-secret creation, Compose services, migrations, seeds, admin mutation, HTTPS/Doctor/browser
proof and their authority belong to the follow-up operator rehearsal. Follow the canonical command
and evidence rows in `plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md`.
