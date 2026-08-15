#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
plan_dir="$root_dir/plans/archive/2026.08.09-RELEASE-READINESS"
evidence_file="$plan_dir/findings/P3.1-livewire-runtime-evidence.md"
mode=${1:-}

usage() {
    echo "Usage: $0 --expect-current-failure|--expect-success" >&2
    exit 64
}

case "$mode" in
    --expect-current-failure|--expect-success) ;;
    *) usage ;;
esac

for state_file in \
    "$plan_dir/phases/P0/P0.4.state.md" \
    "$plan_dir/phases/P0/P0.6.state.md" \
    "$plan_dir/phases/P1/P1.5.state.md" \
    "$plan_dir/phases/P2/P2.6.state.md"; do
    rg -F '**Status:** 🟢 Done' "$state_file" >/dev/null || {
        echo "Required upstream item is not terminal: ${state_file#$root_dir/}" >&2
        exit 1
    }
done

for command in docker curl rg sha256sum sed; do
    command -v "$command" >/dev/null || {
        echo "Required command is unavailable: $command" >&2
        exit 1
    }
done

cd "$root_dir"

for service in postgres redis minio app; do
    container_id=$(docker compose ps -q "$service")
    [[ -n "$container_id" ]] || {
        echo "Required Compose service is not running: $service" >&2
        exit 1
    }

    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")
    [[ "$health" == healthy ]] || {
        echo "Required Compose service is not healthy: $service ($health)" >&2
        exit 1
    }
done

tmp_dir=$(mktemp -d "${TMPDIR:-/tmp}/agelto-livewire-repro.XXXXXX")
trap 'rm -rf "$tmp_dir"' EXIT

html_file="$tmp_dir/admin-login.html"
headers_file="$tmp_dir/livewire.headers"
body_file="$tmp_dir/livewire.body"
route_file="$tmp_dir/livewire-routes.txt"
nginx_file="$tmp_dir/nginx-effective.conf"
logs_file="$tmp_dir/app-logs.txt"

app_port=$(docker compose port app 8080 | sed -E 's/^.*:([0-9]+)$/\1/')
base_url="http://127.0.0.1:$app_port"

curl --noproxy '*' --silent --show-error --fail --output "$html_file" "$base_url/admin/login"
asset_url=$(sed -nE 's#.*<script[^>]+src="([^" ]*livewire[^" ]*\.js[^" ]*)".*#\1#p' "$html_file" | head -n 1)
[[ -n "$asset_url" ]] || {
    echo 'Admin HTML did not contain a Livewire script URL' >&2
    exit 1
}

if [[ "$asset_url" == /* ]]; then
    asset_url="$base_url$asset_url"
fi

http_status=$(curl --noproxy '*' --silent --show-error --output "$body_file" --dump-header "$headers_file" --write-out '%{http_code}' "$asset_url")
content_type=$(awk 'tolower($0) ~ /^content-type:/ { sub(/^[^:]*:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$headers_file")
body_sha256=$(sha256sum "$body_file" | awk '{print $1}')

docker compose exec -T app php artisan route:list --path=livewire >"$route_file"
docker compose exec -T --user root app nginx -T >"$nginx_file" 2>&1
docker compose logs --no-color --tail 200 app >"$logs_file" 2>&1 || true

static_block=$(sed -n '/location ~\* \\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)/,/^[[:space:]]*}/p' "$nginx_file")
[[ -n "$static_block" ]] || {
    echo 'Effective Nginx configuration did not expose the static asset location' >&2
    exit 1
}

route_present=false
if rg -F 'livewire' "$route_file" >/dev/null; then
    route_present=true
fi

vendor_dist_present=false
if docker compose exec -T app sh -lc 'test -f vendor/livewire/livewire/dist/livewire.js'; then
    vendor_dist_present=true
fi

static_location_has_fallback=false
if grep -Eq 'try_files|rewrite|@' <<<"$static_block"; then
    static_location_has_fallback=true
fi

url_matches_versioned_route=false
if [[ "$asset_url" =~ /livewire-[0-9a-f]{8}/livewire\.js ]]; then
    url_matches_versioned_route=true
fi

redact() {
    sed -E 's/((APP_KEY|PASSWORD|SECRET|TOKEN|AUTHORIZATION)[[:space:]]*[:=][[:space:]]*)[^[:space:]]+/\1[REDACTED]/Ig'
}

verdict=REFUTED
expected_chain=false
if [[ "$url_matches_versioned_route" == true && "$http_status" == 404 && "$route_present" == true && "$vendor_dist_present" == true && "$static_location_has_fallback" == false ]]; then
    verdict=PROVED
    expected_chain=true
fi

if [[ "$mode" == --expect-success ]]; then
    if [[ "$http_status" != 200 || "$content_type" != *javascript* || "$route_present" != true || "$vendor_dist_present" != true ]]; then
        verdict=REFUTED
    else
        verdict=PROVED
    fi
fi

{
    echo '# P3.1 Livewire runtime evidence'
    echo
    echo "- Captured at: $(date --iso-8601=seconds)"
    echo "- Git revision: $(git rev-parse HEAD)"
    echo "- Mode: \`$mode\`"
    echo "- Verdict: **$verdict**"
    echo
    echo '## Commands'
    echo
    echo '```bash'
    echo 'docker compose ps'
    echo "curl --noproxy '*' --silent --show-error --fail $base_url/admin/login"
    echo "curl --noproxy '*' --silent --show-error --dump-header <headers> $asset_url"
    echo 'docker compose exec -T app php artisan route:list --path=livewire'
    echo 'docker compose exec -T --user root app nginx -T'
    echo 'docker compose logs --no-color --tail 200 app'
    echo '```'
    echo
    echo '## HTTP chain'
    echo
    echo "- HTML-extracted Livewire URL: \`$asset_url\`"
    echo "- Versioned Livewire URL shape: \`$url_matches_versioned_route\`"
    echo "- Response: \`$http_status\`"
    echo "- Content-Type: \`${content_type:-absent}\`"
    echo "- Body SHA-256: \`$body_sha256\`"
    echo
    echo '## Laravel and vendor evidence'
    echo
    printf '%s\n' "- \`route:list --path=livewire\` contains a Livewire route: \`$route_present\`"
    printf '%s\n' "- \`vendor/livewire/livewire/dist/livewire.js\` exists: \`$vendor_dist_present\`"
    echo
    echo '```text'
    cat "$route_file" | redact
    echo '```'
    echo
    echo '## Effective Nginx location'
    echo
    echo "- Static location has Laravel fallback: \`$static_location_has_fallback\`"
    echo
    echo '```nginx'
    printf '%s\n' "$static_block" | redact
    echo '```'
    echo
    echo '## Redacted app log tail'
    echo
    echo '```text'
    cat "$logs_file" | redact
    echo '```'
    echo
    echo '## Interpretation'
    echo
    if [[ "$mode" == --expect-current-failure && "$expected_chain" == true ]]; then
        echo 'The admin HTML emits the dynamic Livewire URL, Laravel registers the route and vendor contains its distribution, but Nginx selects the static extension location without a fallback and returns 404. P3.2 may add only the Nginx fallthrough repair required for this location.'
    elif [[ "$mode" == --expect-success && "$verdict" == PROVED ]]; then
        echo 'The Livewire route is reachable through the repaired HTTP path. This mode is reserved for P3.2 and later.'
    else
        echo 'The observed chain differs from the declared hypothesis. Do not normalize the discrepancy; P3.2 requires plan-design escalation with this evidence.'
    fi
} >"$evidence_file"

if [[ "$mode" == --expect-current-failure ]]; then
    [[ "$expected_chain" == true ]] || {
        echo "Expected current failure chain was not observed; see ${evidence_file#$root_dir/}" >&2
        exit 1
    }
else
    [[ "$verdict" == PROVED ]] || {
        echo "Expected success chain was not observed; see ${evidence_file#$root_dir/}" >&2
        exit 1
    }
fi

echo "Livewire runtime evidence captured: ${evidence_file#$root_dir/} ($verdict)"
