#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
project=${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}
env_file=${ENV_FILE:-.env.example}
compose=(docker compose --project-directory "$root_dir" --project-name "$project" --env-file "$root_dir/$env_file" -f "$root_dir/docker-compose.yml")

asset=$(
    "${compose[@]}" exec -T app php artisan tinker --execute='foreach (\Filament\Support\Facades\FilamentAsset::getScripts() as $asset) { if (! $asset->isRemote()) { echo $asset->getPublicPath(); break; } }'
)
[[ "$asset" == /var/www/html/public/* ]] || { echo 'Resolved asset escaped public root' >&2; exit 2; }

bash "$root_dir/docker/tests/assets-contract.sh" check
before_hash=$("${compose[@]}" exec -T app sha256sum "$asset" | awk '{print $1}')
before_image=$("${compose[@]}" images -q app)

"${compose[@]}" exec -T app php artisan optimize:clear
"${compose[@]}" exec -T app php artisan optimize
"${compose[@]}" restart app horizon scheduler
if ! "${compose[@]}" exec -T app test -f "$asset"; then
    echo 'Asset must exist before the controlled damage fixture' >&2
    exit 1
fi

"${compose[@]}" exec -T app rm -- "$asset"

if "${compose[@]}" exec -T app test -f "$asset"; then
    echo 'Controlled damage fixture did not delete the selected asset' >&2
    exit 1
fi

if bash "$root_dir/docker/tests/assets-contract.sh" check; then
    echo 'Asset contract unexpectedly stayed green after deleting one generated asset' >&2
    exit 1
fi

bash "$root_dir/docker/tests/assets-contract.sh" repair
after_hash=$("${compose[@]}" exec -T app sha256sum "$asset" | awk '{print $1}')
after_image=$("${compose[@]}" images -q app)

[[ "$before_hash" == "$after_hash" ]] || { echo 'Recovered asset hash changed' >&2; exit 1; }
[[ "$before_image" == "$after_image" ]] || { echo 'Repair changed the application image' >&2; exit 1; }
