#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
mode=${1:-check}
compose=(docker compose --project-directory "$root_dir" --project-name "${COMPOSE_PROJECT_NAME:-agelto_dev}" --env-file "$root_dir/${ENV_FILE:-.env}" -f "$root_dir/docker-compose.yml")

asset_paths() {
    "${compose[@]}" exec -T app php artisan tinker --execute='foreach ([...\Filament\Support\Facades\FilamentAsset::getScripts(), ...\Filament\Support\Facades\FilamentAsset::getStyles()] as $asset) { if (! $asset->isRemote()) { echo $asset->getPublicPath().PHP_EOL; } } foreach (["vendor/livewire/manifest.json", "vendor/livewire/livewire.js", "vendor/livewire/livewire.min.js"] as $path) { echo public_path($path).PHP_EOL; }'
}

case "$mode" in
    check)
        while IFS= read -r path; do
            "${compose[@]}" exec -T app test -f "$path"
        done < <(asset_paths)
        ;;
    sync|repair)
        "${compose[@]}" exec -T app php artisan filament:assets --no-interaction
        "${compose[@]}" exec -T app php artisan livewire:publish --assets --no-interaction
        bash "$0" check
        ;;
    *)
        echo 'usage: assets-contract.sh {check|sync|repair}' >&2
        exit 2
        ;;
esac
