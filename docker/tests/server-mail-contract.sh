#!/usr/bin/env bash
# Read-only Compose render; never pulls an image or contacts production.
set -euo pipefail
root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
AGELTO_SMTP_IMAGE=boky/postfix:5.1.0@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    docker compose --env-file "$root_dir/docker/host/mail/mail.env.example" \
    -f "$root_dir/docker/host/mail/compose.yml" --profile smtp config --format json | \
    python3 -c 'import json,sys
c=json.load(sys.stdin); s=c["services"]["smtp"]
assert c["name"] == "agelto_mail"
assert s["profiles"] == ["smtp"]
assert not s.get("ports"), "SMTP must not be published to the host/internet"
assert "container_name" not in s
assert s["environment"]["ALLOWED_SENDER_DOMAINS"] == "agelto.ru"
assert s["environment"]["POSTFIX_mynetworks"] == "127.0.0.0/8,172.21.0.0/16"
assert "@sha256:" in s["image"]
assert c["networks"]["app_network"]["name"] == "agelto_prod_agelto_network"
assert c["volumes"]["mail_queue"]["name"] == "agelto_mail_mail_queue"
assert c["volumes"]["dkim_keys"]["name"] == "agelto_mail_dkim_keys"
print("Private server SMTP Compose contract passed")'
