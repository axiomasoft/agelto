#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

for contract in vendor-contract.sh secrets-contract.sh preflight-contract.sh bootstrap-contract.sh seed-admin-contract.sh doctor-contract.sh; do
    bash "$root_dir/docker/tests/$contract"
done

bash "$root_dir/docker/tests/compose-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/prod-image-contract.sh" doctor

echo 'P2 release contract passed'
