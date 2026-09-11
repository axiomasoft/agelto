#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

rg -n '"laravel/doctor": "\^0\.1\.0"' "$root_dir/composer.json" >/dev/null
rg -n 'target: doctor|agelto/doctor:|profiles: \[doctor\]' "$root_dir/docker/php/Dockerfile" "$root_dir/docker-compose.yml" "$root_dir/docker-compose.prod.yml" >/dev/null
rg -n 'doctor --format=agent --fail-on=warn --no-interaction' "$root_dir/docker/Makefile" >/dev/null
! rg -n -- '--fix|fail-on=never' "$root_dir/docker/Makefile" "$root_dir/docker-compose.prod.yml"

# Production is intentionally configured through Compose plus file-backed secrets.  A runtime
# image has neither an `.env` file nor a Composer write/audit workflow; those checks belong to CI.
for diagnostic in EnvironmentFileExists EnvironmentFileIsGitIgnored ComposerAutoloadIsValid ComposerAuditPasses BootstrapCacheMatchesEnvironment; do
    rg -F "\\Laravel\\Doctor\\Diagnostics\\${diagnostic}::class" "$root_dir/config/doctor.php" >/dev/null
done
rg -F 'storage/app' "$root_dir/docker/php/Dockerfile" >/dev/null
! rg -F "printf '%s\\\\n' \"\$VENDOR_LOCK_SHA\"" "$root_dir/docker/php/Dockerfile"

echo 'Doctor contract passed'
