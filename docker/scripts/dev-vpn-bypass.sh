#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${1:-.env}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if [[ ! -f "$ENV_FILE" ]]; then
    exit 0
fi

read_env_var() {
    local key="$1"
    local val
    val="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -n 1 | cut -d'=' -f2- || true)"
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"
    printf '%s' "$val"
}

ENABLED="$(read_env_var "LOCAL_VPN_BYPASS_ENABLED")"
if [[ "$ENABLED" != "true" && "$ENABLED" != "1" ]]; then
    exit 0
fi

PROXY_URL="$(read_env_var "PLAYWRIGHT_MCP_PROXY_SERVER")"
if [[ -z "$PROXY_URL" ]]; then
    echo "[vpn-bypass] LOCAL_VPN_BYPASS_ENABLED=true, но PLAYWRIGHT_MCP_PROXY_SERVER не задан в $ENV_FILE" >&2
    exit 1
fi

PROXY_HOST="$(printf '%s' "$PROXY_URL" | sed -E 's#^[a-zA-Z0-9+.-]+://([^/@]+@)?([^:/?]+).*#\2#')"
PROXY_PORT="$(printf '%s' "$PROXY_URL" | sed -E 's#^[a-zA-Z0-9+.-]+://([^/@]+@)?[^:/?]+:([0-9]+).*#\2#')"
if [[ ! "$PROXY_HOST" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || [[ ! "$PROXY_PORT" =~ ^[0-9]+$ ]]; then
    echo "[vpn-bypass] Некорректный хост:порт прокси '$PROXY_HOST:$PROXY_PORT'" >&2
    exit 1
fi

DEFAULT_LINE="$(ip route show default | grep -vE 'dev (amn|tun|wg|docker|br-|veth)' | head -n 1 || true)"
if [[ -z "$DEFAULT_LINE" ]]; then
    echo "[vpn-bypass] Не найден физический default-маршрут вне VPN" >&2
    exit 1
fi

LAN_GW="$(printf '%s' "$DEFAULT_LINE" | awk '{for(i=1;i<=NF;i++) if($i=="via") print $(i+1)}')"
LAN_DEV="$(printf '%s' "$DEFAULT_LINE" | awk '{for(i=1;i<=NF;i++) if($i=="dev") print $(i+1)}')"

if [[ -z "$LAN_GW" || -z "$LAN_DEV" ]]; then
    echo "[vpn-bypass] Не удалось определить шлюз/интерфейс из: $DEFAULT_LINE" >&2
    exit 1
fi

CURRENT_ROUTE="$(ip route get "$PROXY_HOST" 2>/dev/null | head -n 1 || true)"

if command -v nmcli >/dev/null 2>&1; then
    CONN_NAME="$(nmcli -t -f NAME,DEVICE connection show --active | awk -F: -v dev="$LAN_DEV" '$2 == dev {print $1; exit}')"
    if [[ -n "$CONN_NAME" ]]; then
        EXISTING_NM_ROUTES="$(nmcli -g ipv4.routes connection show "$CONN_NAME" 2>/dev/null || true)"
        EXISTING_NM_RULES="$(nmcli -g ipv4.routing-rules connection show "$CONN_NAME" 2>/dev/null || true)"
        CHANGED=0
        if [[ "$EXISTING_NM_ROUTES" != *"${PROXY_HOST}/32"* ]]; then
            nmcli connection modify "$CONN_NAME" +ipv4.routes "${PROXY_HOST}/32 ${LAN_GW}"
            CHANGED=1
        fi
        if [[ "$EXISTING_NM_RULES" != *"to ${PROXY_HOST}/32 table 254"* ]]; then
            nmcli connection modify "$CONN_NAME" +ipv4.routing-rules "priority 100 to ${PROXY_HOST}/32 table 254"
            CHANGED=1
        fi
        if [[ "$EXISTING_NM_RULES" != *"from ${PROXY_HOST}/32 table 254"* ]]; then
            nmcli connection modify "$CONN_NAME" +ipv4.routing-rules "priority 100 from ${PROXY_HOST}/32 table 254"
            CHANGED=1
        fi
        if [[ "$CHANGED" -eq 1 ]] || [[ "$CURRENT_ROUTE" == *"dev amn"* ]] || [[ "$CURRENT_ROUTE" == *"dev tun"* ]] || [[ "$CURRENT_ROUTE" == *"dev wg"* ]]; then
            nmcli connection up "$CONN_NAME" >/dev/null
        fi
    fi
fi

VERIFY_ROUTE="$(ip route get "$PROXY_HOST" 2>/dev/null | head -n 1 || true)"
if [[ "$VERIFY_ROUTE" == *"dev amn"* ]] || [[ "$VERIFY_ROUTE" == *"dev tun"* ]] || [[ "$VERIFY_ROUTE" == *"dev wg"* ]]; then
    echo "[vpn-bypass] ОШИБКА: маршрут к прокси $PROXY_HOST всё ещё идёт через VPN: $VERIFY_ROUTE" >&2
    exit 1
fi

SOCKET_IN_CONTAINER="$(read_env_var "OUTBOUND_PROXY_UNIX_SOCKET")"
if [[ -n "$SOCKET_IN_CONTAINER" ]]; then
    PROJECT_NAME="$(read_env_var "COMPOSE_PROJECT_NAME")"
    PROJECT_NAME="${PROJECT_NAME:-agelto_dev}"
    RELAY_CONTAINER="${PROJECT_NAME}-vpn-bypass"
    APP_IMAGE="${PROJECT_NAME}-app"
    HOST_SOCK_PATH="${ROOT_DIR}/storage/logs/vpn-bypass.sock"

    docker rm -f "$RELAY_CONTAINER" >/dev/null 2>&1 || true
    rm -f "$HOST_SOCK_PATH"

    docker run -d \
        --name "$RELAY_CONTAINER" \
        --restart unless-stopped \
        --network host \
        --entrypoint php \
        -v "${ROOT_DIR}/storage/logs:/var/www/html/storage/logs" \
        "$APP_IMAGE" \
        -r "
        pcntl_signal(SIGCHLD, SIG_IGN);
        \$sock = '/var/www/html/storage/logs/vpn-bypass.sock';
        @unlink(\$sock);
        \$srv = stream_socket_server('unix://' . \$sock, \$errno, \$errstr);
        if (!is_resource(\$srv)) { fwrite(STDERR, \$errstr . PHP_EOL); exit(1); }
        chmod(\$sock, 0666);
        while (true) {
            \$c = @stream_socket_accept(\$srv, -1);
            if (!is_resource(\$c)) continue;
            \$pid = pcntl_fork();
            if (\$pid === 0) {
                fclose(\$srv);
                \$r = @stream_socket_client('tcp://${PROXY_HOST}:${PROXY_PORT}', \$re, \$rs, 10);
                if (!is_resource(\$r)) { fclose(\$c); exit(1); }
                stream_set_blocking(\$c, false);
                stream_set_blocking(\$r, false);
                \$c2r = ''; \$r2c = '';
                while (true) {
                    \$rd = [];
                    if (strlen(\$c2r) < 65536 && !feof(\$c)) \$rd[] = \$c;
                    if (strlen(\$r2c) < 65536 && !feof(\$r)) \$rd[] = \$r;
                    \$wr = [];
                    if (\$c2r !== '') \$wr[] = \$r;
                    if (\$r2c !== '') \$wr[] = \$c;
                    if (\$rd === [] && \$wr === []) break;
                    \$ex = null;
                    if (@stream_select(\$rd, \$wr, \$ex, 30) <= 0) break;
                    foreach (\$rd as \$s) {
                        \$d = @fread(\$s, 65536);
                        if (\$d === false || \$d === '') {
                            if (\$s === \$c && \$c2r === '') break 2;
                            if (\$s === \$r && \$r2c === '') break 2;
                        } else {
                            if (\$s === \$c) \$c2r .= \$d; else \$r2c .= \$d;
                        }
                    }
                    foreach (\$wr as \$s) {
                        if (\$s === \$r && \$c2r !== '') {
                            \$n = @fwrite(\$r, \$c2r);
                            if (\$n === false) break 2;
                            if (\$n > 0) \$c2r = substr(\$c2r, \$n);
                        } elseif (\$s === \$c && \$r2c !== '') {
                            \$n = @fwrite(\$c, \$r2c);
                            if (\$n === false) break 2;
                            if (\$n > 0) \$r2c = substr(\$r2c, \$n);
                        }
                    }
                }
                fclose(\$c); fclose(\$r); exit(0);
            }
            fclose(\$c);
        }
        " >/dev/null

    for _ in {1..20}; do
        [[ -S "$HOST_SOCK_PATH" ]] && break
        sleep 0.1
    done
    if [[ ! -S "$HOST_SOCK_PATH" ]]; then
        echo "[vpn-bypass] ОШИБКА: Unix-сокет $HOST_SOCK_PATH не поднялся" >&2
        exit 1
    fi
fi

echo "[vpn-bypass] OK: маршрут к прокси $PROXY_HOST:$PROXY_PORT идёт напрямую в обход VPN ($VERIFY_ROUTE)"
