---
audience: operator
type: runbook
status: living
---

# Network and TLS preparation

`docker/host/nginx/agelto.conf` is a tracked template. It terminates TLS and proxies only to the
loopback application endpoint `127.0.0.1:8080`; containers do not become public upstreams. Replace
the three `__AGELTO_*__` placeholders in a temporary file, then run `nginx -t` before installing or
reloading it. The local contract performs this syntax check with an ephemeral certificate.

`8080` — default, а не требование: на shared host выберите свободный loopback port и замените
upstream одновременно с `APP_PORT`. На `agelto.ru` используется `8082`. Не устанавливайте
TLS template до реального certificate: сначала отдельный HTTP-only challenge vhost.
NGINX 1.28: `listen 443 ssl;`, `listen [::]:443 ssl;`, `http2 on;` вместо deprecated
`listen ... http2`. Сохраните собственный backup, проверьте `nginx -t`, затем reload.
Не меняйте чужие sites/default/certificates. Full commands: [manual TLS](manual-deployment.md#6-dns-nginx-и-tls).
Проверяйте `/up`, HTTPS-ссылки на assets и запросы Livewire. Если приложение видит подключение
хостового NGINX с адреса Docker-шлюза, задайте этот точный адрес в `TRUSTED_PROXIES`.
По умолчанию Laravel доверяет loopback. Внешний NGINX публикует HSTS для HTTPS и принимает
запросы размером до 200 MiB с таймаутом чтения прокси 300 секунд.

The operator rehearsal owns every external action in this order:

1. confirm that the intended A/AAAA records resolve to the approved host;
2. install the HTTP-only challenge configuration and pass `nginx -t`;
3. issue the certificate with Certbot from the official Ubuntu package path;
4. substitute the exact certificate/key paths, pass `nginx -t` again, and reload NGINX;
5. verify HTTPS, redirects, forwarded headers and WebSocket upgrade through the public hostname;
6. run `certbot renew --dry-run` and capture only redacted certificate identity/expiry evidence.

Do not use `--no-check-certificate`, a public container upstream, an unvalidated reload, or a
certificate issued before DNS ownership is confirmed. DNS, issuance, reload and public probes are
deliberately not executed by the current local preparation plan. Canonical commands and required
evidence fields are listed in текущий inventory команд в `commands.md`.
