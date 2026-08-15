---
audience: operator
type: runbook
status: living
---

# Deploy-key rotation and recovery

Rotate one repository key at a time; the Agelto and AzGuard aliases deliberately use independent
Ed25519 private keys. First run `repositories:prepare` and retain the old key. Enrol the new public
key as a **read-only** deploy key for that same repository, verify the new alias with
`repositories:sync`, then revoke the old key in GitHub. Do not use a personal credential or turn on
write access to bridge the transition.

If the verification fails, leave the other repository identity unchanged, restore the last known
working key only from the host's root-protected recovery procedure, and remove the failed deploy key
from GitHub. A lost or exposed private key is revoked in GitHub before replacement; neither private
key is backed up in this repository, the production profile, or P12 evidence.

The operator record contains the repository, public-key fingerprint, enrollment/revocation time,
canonical origin and pinned SHA. It never contains a private key, token, profile value, raw
`known_hosts` entry or SSH diagnostic with secret material.
