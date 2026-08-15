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
bash docker/scripts/production-profile-check.sh check --profile "$(pwd)/docker/host/production-profile"
```

Both checks reject symlinks, group/world access, duplicate or unknown keys, placeholders, public
application binds, non-full source SHAs and non-absolute secret-file paths. Do not `source` the
profile. Repository, backup, release and rehearsal commands receive its path and keep all values
redacted; evidence records hashes/identifiers only.
