# Network and TLS preparation

`docker/host/nginx/agelto.conf` is a tracked template. It terminates TLS and proxies only to the
loopback application endpoint `127.0.0.1:8080`; containers do not become public upstreams. Replace
the three `__AGELTO_*__` placeholders in a temporary file, then run `nginx -t` before installing or
reloading it. The local contract performs this syntax check with an ephemeral certificate.

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
evidence fields are listed in `plans/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md`.
