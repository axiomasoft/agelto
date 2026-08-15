#!/usr/bin/env bash
set -euo pipefail

plan_dir='plans/2026.08.09-RELEASE-READINESS'
report="$plan_dir/findings/P10-security-audit.md"

task_root="$(codex plugin marketplace list --json | python3 -c '
import json
import pathlib
import sys

roots = [
    item["root"]
    for item in json.load(sys.stdin).get("marketplaces", [])
    if item.get("name") == "swissknifeman"
    and (pathlib.Path(item["root"]) / "packages/task/lib/task").is_dir()
]

if len(roots) != 1:
    raise SystemExit("expected exactly one installed Swissknifeman marketplace root")

print(roots[0])
'
)"

require() {
    grep -Fq -- "$1" "$report" || {
        printf 'missing report evidence: %s\n' "$1" >&2
        exit 1
    }
}

test -f "$report"
test "$(sed -n 's/^\*\*Status:\*\* //p' "$plan_dir/phases/P10/P10.1.state.md")" = '🟢 Done'
git diff --quiet 469acf640fc95944a3accc378445190dbca2a9ba -- app routes resources/views config bootstrap composer.json composer.lock

test -d vendor/axioma-studio/azguard-core
test -d vendor/axioma-studio/azguard-filament
composer show --locked axioma-studio/azguard-core --no-ansi >/dev/null
composer show --locked axioma-studio/azguard-filament --no-ansi >/dev/null

for evidence in \
    '## Coverage manifest' \
    '## Checkpoint → repeat delta' \
    '## Deterministic sharp-edge inventory' \
    '## Finding ledger' \
    'P10.2-S01' \
    'P10.2-S02' \
    'P10.2-S03' \
    'Severity / confidence' \
    'Input → sink chain' \
    'Owner / regression seam' \
    '## False-positive ledger' \
    '## Release-blocker list' \
    'D29' \
    'D30' \
    'verified-safe' \
    'finding'; do
    require "$evidence"
done

! rg -n --glob '*.php' "'verify'\\s*=>\\s*false|\"verify\"\\s*=>\\s*false" app config
! rg -n --glob '*.php' --glob '*.blade.php' '\{!!|@js\(|Js::from\(|v-html|unserialize\(' app routes resources/views config bootstrap
rg -Fq "return ['proxy' => \$envProxy];" app/Services/Procurement/Proxy/PoolOutboundProxy.php
rg -Fq "FileUpload::make('source')" app/Filament/Resources/Procurement/Ai/Fixtures/Schemas/FixtureForm.php
rg -Fq "'alias', 'set', self::ALIAS, \$disk['endpoint'], \$disk['key'], \$disk['secret']" app/Services/Storage/MinioLifecycleManager.php
rg -q --glob '*.php' 'SourceTlsTrust' app
rg -q --glob '*.php' 'ProxyResolver' app
rg -q --glob '*.php' 'ArchiveEntryValidator' app
rg -q --glob '*.php' 'PrivateDocumentationController' app routes

docker compose exec -T app ./vendor/bin/pest \
    tests/Feature/Documentation/PrivateDocumentationAccessTest.php \
    tests/Feature/Documentation/PrivateDocumentationResponseTest.php \
    tests/Feature/Llm/GeminiProxyRoutingTest.php \
    tests/Feature/Ai/FixtureUploadTest.php \
    tests/Feature/Infra/StorageLifecycleTest.php \
    tests/Unit/Documentation/PrivateDocumentationLocatorTest.php \
    tests/Unit/Procurement/Prepare/ArchiveEntryValidatorTest.php

if [[ "${TASK_DOWNSTREAM_NESTED_VALIDATION:-}" != '1' ]]; then
    python3 "$task_root/packages/task/scripts/plan-lint.py" "$plan_dir"
    python3 "$task_root/packages/task/scripts/plan-views.py" state --all --plan-dir "$plan_dir" --check
    python3 "$task_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$plan_dir" --check
    python3 "$task_root/packages/task/scripts/plan-views.py" status --check --plan-dir "$plan_dir"
fi
python3 plans/verify-citations.py "$plan_dir"

printf 'P10.2 security audit contract: PASS\n'
