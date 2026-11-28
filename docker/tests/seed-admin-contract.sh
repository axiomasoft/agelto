#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

bash "$root_dir/docker/tests/bootstrap-contract.sh"
rg -n -- '--password=' "$root_dir/app" "$root_dir/docker/Makefile" "$root_dir/docker/scripts" && exit 1 || true
rg -n 'admin:create-initial.*password-file|admin:reset-password.*password-file' "$root_dir/docker/Makefile" >/dev/null
rg -n 'BaseSeeder|DemoSeeder|CreateInitialAdminAction|ResetAdminPasswordAction' "$root_dir/database" "$root_dir/app" >/dev/null

echo 'Seed/admin contract passed'
