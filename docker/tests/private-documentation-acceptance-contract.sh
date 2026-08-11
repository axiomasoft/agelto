#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

[[ -n "${E2E_ADMIN_EMAIL:-}" ]] || { echo 'E2E_ADMIN_EMAIL is required' >&2; exit 2; }
[[ -n "${E2E_ADMIN_PASSWORD_FILE:-}" ]] || { echo 'E2E_ADMIN_PASSWORD_FILE is required' >&2; exit 2; }

bash "$root_dir/docker/tests/private-documentation-response-contract.sh"
bash "$root_dir/docker/tests/documentation-build-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/clean-install-e2e.sh"
git -C "$root_dir" diff --check

echo 'Private documentation acceptance contract passed'
