#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

for contract in vendor-contract.sh secrets-contract.sh preflight-contract.sh bootstrap-contract.sh seed-admin-contract.sh doctor-contract.sh; do
    bash "$root_dir/docker/tests/$contract"
done

bash "$root_dir/docker/tests/compose-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/prod-image-contract.sh" doctor

registry_name="agelto-runtime-registry-$RANDOM"
registry_id=$(docker run --detach --rm --name "$registry_name" --publish 127.0.0.1::5000 registry:3)
trap 'docker stop "$registry_id" >/dev/null 2>&1 || true' EXIT
registry_port=$(docker port "$registry_id" 5000/tcp | sed -E 's/^.*:([0-9]+)$/\1/')
bash "$root_dir/docker/scripts/image-pair.sh" \
    --root "$root_dir" \
    --tag "runtime-contract-$(git -C "$root_dir" rev-parse --short HEAD)" \
    --registry "127.0.0.1:$registry_port" \
    --publish

echo 'release contract passed'
