#!/usr/bin/env bash

set -euo pipefail

if [[ -z "${AZGUARD_CI_TOKEN:-}" ]]; then
    echo 'Required private-package credential is unavailable.' >&2
    exit 1
fi

if [[ "$AZGUARD_CI_TOKEN" == *$'\n'* || "$AZGUARD_CI_TOKEN" == *$'\r'* ]]; then
    echo 'Private-package credential has an invalid format.' >&2
    exit 1
fi

export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0="url.https://x-access-token:${AZGUARD_CI_TOKEN}@github.com/.insteadOf"
export GIT_CONFIG_VALUE_0='https://github.com/'

exec "$@"
