#!/usr/bin/env bash
set -euo pipefail
case ${1:-} in check) echo 'APP DB REDIS MINIO: before/next/applied/rolled-back fingerprints remain redacted';; *) echo 'apply requires future authority' >&2; exit 2;; esac
