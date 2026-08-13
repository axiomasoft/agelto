#!/usr/bin/env bash
set -euo pipefail

plan_dir='plans/2026.08.09-RELEASE-READINESS'
report="$plan_dir/findings/P10-security-audit.md"

require() {
    grep -Fq -- "$1" "$report" || {
        printf 'missing report evidence: %s\n' "$1" >&2
        exit 1
    }
}

test -f "$report"
test "$(sed -n 's/^\*\*Status:\*\* //p' "$plan_dir/phases/P10/P10.1.state.md")" = '🟢 Done'
git diff --quiet 469acf640fc95944a3accc378445190dbca2a9ba -- app routes resources/views config bootstrap composer.json composer.lock

for evidence in \
    '## Coverage manifest' \
    '## Finding ledger' \
    'P10.2-S01' \
    'P10.2-S02' \
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

python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py "$plan_dir"
python3 plans/verify-citations.py "$plan_dir"

printf 'P10.2 security audit contract: PASS\n'
