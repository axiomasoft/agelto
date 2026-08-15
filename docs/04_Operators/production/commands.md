---
audience: operator
type: reference
status: living
---

# Production commands

The canonical command inventory is generated at
[`P12-command-matrix.md`](../../../plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md)
from the root Make delegation, `docker/Makefile` `.PHONY` targets and declared P12 script modes.

Run `python3 docker/scripts/check-command-reference.py check` before following any copied command.
A stale/missing/duplicate/wrong-environment or unguarded destructive row fails closed. Local fixture
output is never runtime evidence; external/mutating rows remain blank until the follow-up rehearsal.
