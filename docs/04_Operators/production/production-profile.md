---
audience: operator
type: how-to
status: living
---

# Production host profile

Copy `docker/host/production-profile.example` to `docker/host/production-profile`, fill every
value, then set owner `root` and mode `0600`. The actual profile is Git-ignored and never appears
in image builds or command output.

It is deliberately a small, closed `KEY=value` file: it names the deploy public-key file, ports,
capacity floor, Docker data path, and paths to separate root-only secret files. It is not shell code
and is parsed without `source` or `eval`.

```bash
make -f docker/Makefile host:check PROFILE=docker/host/production-profile
```
