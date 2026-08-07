#!/usr/bin/env bash
#
# Запуск Playwright MCP с прокси из .env.
#
# Проблема 1 — прокси. Chromium-флаг --proxy-server игнорирует встроенные в URL креды
# (http://user:pass@host:port) → прокси отдаёт 407 → net::ERR_INVALID_AUTH_CREDENTIALS.
# Решение: передаём прокси-креды Playwright раздельно через browser.launchOptions.proxy
# (server + username + password) во временном конфиге --config.
#
# Проблема 2 — TLS российских госплощадок. zakupki.gov.ru отдаёт цепочку Минцифры
# (корень 2022 + Sub CA 2024), которой нет в дефолтном наборе корней Chromium →
# browser_navigate падает с net::ERR_CERT_AUTHORITY_INVALID. Бандл в репозитории
# (docker/php/certs/russian-trusted-ca.pem) Chromium НЕ читает: он берёт системный
# NSS-store, а NODE_EXTRA_CA_CERTS влияет только на Node, не на сетевой стек браузера.
# Решение: contextOptions.ignoreHTTPSErrors для контекстов РАЗВЕДКИ.
#
# Граница: послабление живёт ТОЛЬКО в этом launcher'е — инструменте ручной разведки.
# Боевой HTTP-клиент площадки проверку НЕ ослабляет: у него узкая опция `verify` с бандлом
# по карте `crawler.tls.bundles` (единственный носитель — SourceTlsTrust), `verify => false`
# запрещён абсолютно и закреплён тестом (D125 плана 2026.07.31-SYS-INTEGRITY). Вносить
# бандл в системный трест образа тоже запрещено — доверие российскому УЦ получил бы весь
# контейнер, включая исходящий канал к LLM-провайдеру.
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

CONFIG="$(mktemp "${TMPDIR:-/tmp}/playwright-mcp-XXXXXX")"
chmod 600 "$CONFIG"
trap 'rm -f "$CONFIG"' EXIT

# Раскладываем URL на server/username/password и пишем JSON-конфиг.
# Прокси может быть не задан — тогда в конфиге остаётся только contextOptions.
PWMCP_PROXY="$RAW" PWMCP_CONFIG="$CONFIG" node -e '
  const fs = require("fs");
  const raw = process.env.PWMCP_PROXY;
  const launchOptions = {};
  if (raw) {
    let proxy;
    try {
      const u = new URL(raw);
      proxy = { server: `${u.protocol}//${u.host}` };
      if (u.username) proxy.username = decodeURIComponent(u.username);
      if (u.password) proxy.password = decodeURIComponent(u.password);
    } catch {
      proxy = { server: raw };
    }
    launchOptions.proxy = proxy;
  }
  fs.writeFileSync(process.env.PWMCP_CONFIG, JSON.stringify({
    browser: {
      launchOptions,
      // Цепочка Минцифры у госплощадок — см. шапку файла. Послабление ограничено
      // этим launcher-ом разведки и не относится к боевому клиенту.
      contextOptions: { ignoreHTTPSErrors: true },
    },
  }));
'

# Без exec — чтобы trap почистил временный конфиг после остановки сервера.
npx -y @playwright/mcp@latest --config "$CONFIG" "$@"
