#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

# Local fixtures verify the guarded production commands; live deployment evidence is separate.
for contract in \
    deploy-repositories-contract.sh \
    ingress-backup-contract.sh \
    first-production-deploy-contract.sh \
    release-recovery-contract.sh \
    command-reference-contract.sh; do
    bash "$root/docker/tests/$contract"
done

git -C "$root" diff --check -- docker/scripts docker/tests docs/dev docs/operators
printf 'Production preparation contracts passed\n'
