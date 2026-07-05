#!/usr/bin/env bash
#
# Запуск Playwright MCP с прокси из .env.
#
# Проблема: Chromium-флаг --proxy-server игнорирует встроенные в URL креды
# (http://user:pass@host:port) → прокси отдаёт 407 → net::ERR_INVALID_AUTH_CREDENTIALS.
# Решение: передаём прокси-креды Playwright раздельно через browser.launchOptions.proxy
# (server + username + password) во временном конфиге --config.
#
# Креды живут только в .env (PLAYWRIGHT_MCP_PROXY_SERVER=http://user:pass@host:port)
# и во временном конфиге (mktemp, mode 600), который удаляется при выходе.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Значение из .env, со снятием возможных обрамляющих кавычек.
RAW="$(grep -E '^PLAYWRIGHT_MCP_PROXY_SERVER=' "$ROOT/.env" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
RAW="${RAW%\"}"; RAW="${RAW#\"}"
RAW="${RAW%\'}"; RAW="${RAW#\'}"

# Прокси не задан — поднимаем без него.
if [ -z "$RAW" ]; then
    exec npx -y @playwright/mcp@latest "$@"
fi

CONFIG="$(mktemp "${TMPDIR:-/tmp}/playwright-mcp-XXXXXX")"
trap 'rm -f "$CONFIG"' EXIT

# Раскладываем URL на server/username/password и пишем JSON-конфиг.
PWMCP_PROXY="$RAW" PWMCP_CONFIG="$CONFIG" node -e '
  const fs = require("fs");
  let proxy;
  try {
    const u = new URL(process.env.PWMCP_PROXY);
    proxy = { server: `${u.protocol}//${u.host}` };
    if (u.username) proxy.username = decodeURIComponent(u.username);
    if (u.password) proxy.password = decodeURIComponent(u.password);
  } catch {
    proxy = { server: process.env.PWMCP_PROXY };
  }
  fs.writeFileSync(process.env.PWMCP_CONFIG, JSON.stringify({ browser: { launchOptions: { proxy } } }));
'

# Без exec — чтобы trap почистил временный конфиг после остановки сервера.
npx -y @playwright/mcp@latest --config "$CONFIG" "$@"
