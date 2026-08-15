# P12 local preparation RAG — 2026-08-15

## Scope and method

D89 требует локально проверяемый preparation package, а не имитацию production deployment.
Проверка проведена девятью отдельными `perplexity-web search_advanced` запросами с источником
`web` и ограничением на первичные upstream-документы. Темы: Ubuntu 24.04, Docker Engine,
GitHub SSH/deploy keys, NGINX, Certbot, PostgreSQL, systemd timers, MinIO/AIStor и отдельно
Community MinIO release boundary.

## Repo-grounded baseline

- P12.1 уже создал локальный host carrier и terminal evidence; его `🟠 Done with deviations`
  state и journal не переписываются. RAG:— (repo-grounded: `phases/P12/P12.1.state.md`,
  `artifacts/P12-host-evidence.md`, commits `c8145ee5`/`69b47f53`).
- P12.2 уже имеет product commit `ad76797a`: `deploy-repositories.sh`, локальный contract test и
  operator docs. Внешний proof был parked только из-за host/enrollment authority; D89 меняет
  acceptance remaining P12 на локальные fixtures/docs/scripts. RAG:— (repo-grounded:
  `journal.jsonl:470-473`, `decisions/D89-p12-local-preparation-boundary.md`).
- Production Compose фиксирует PostgreSQL 17 и Community MinIO
  `RELEASE.2025-04-22T22-12-26Z`; app/PostgreSQL/Redis/MinIO host ports loopback-bound.
  RAG:— (repo-grounded: `docker-compose.prod.yml`).

## Official-source facts

### Ubuntu 24.04 and Docker Engine

- Ubuntu требует проверять OpenSSH configuration через `sshd -t` до restart; UFW сначала
  получает allow-rule для SSH и только затем включается. Automatic security updates управляются
  `/etc/apt/apt.conf.d/20auto-upgrades` и `50unattended-upgrades`; dry-run —
  `unattended-upgrade -v --dry-run`. Источники:
  [OpenSSH server](https://documentation.ubuntu.com/server/how-to/security/openssh-server/index.html),
  [Firewall](https://documentation.ubuntu.com/server/how-to/security/firewalls/index.html),
  [Automatic updates](https://ubuntu.com/server/docs/how-to/software/automatic-updates/),
  проверено 2026-08-15. Confidence: high. RAG:✅.
- Docker для Ubuntu 24.04 устанавливается из официального apt repository пакетами
  `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin` и
  `docker-compose-plugin`; convenience script не рекомендуется для production. Membership в
  `docker` group даёт root-level privilege. Источник:
  [Install Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/),
  [Linux post-install](https://docs.docker.com/engine/install/linux-postinstall/), проверено
  2026-08-15. Confidence: high. RAG:✅.
- Published container ports могут обходить UFW; policy должна учитывать Docker chains и
  `DOCKER-USER`. Remote daemon остаётся Unix-socket-only; TCP без TLS не является безопасным
  default. Для container logs Docker рекомендует rotation либо rotating driver. Источники:
  [Packet filtering and firewalls](https://docs.docker.com/engine/network/packet-filtering-firewalls/),
  [Remote access](https://docs.docker.com/engine/daemon/remote-access/),
  [Daemon logs](https://docs.docker.com/engine/daemon/logs/), проверено 2026-08-15.
  Confidence: high. RAG:✅.

### GitHub repository identity

- Deploy key принадлежит одному repository, read-only по умолчанию и не имеет автоматического
  expiry; один server с несколькими repositories использует отдельные key pairs и SSH aliases.
  Источник: [Managing deploy keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys),
  проверено 2026-08-15. Confidence: high. RAG:✅.
- GitHub публикует SSH host fingerprints; unattended carrier обязан prepopulate controlled
  `known_hosts` и fail closed на unknown/changed host key. Источники:
  [GitHub SSH fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints),
  [Testing SSH](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/testing-your-ssh-connection),
  проверено 2026-08-15. Confidence: high. RAG:✅.

### NGINX and Certbot

- NGINX reverse proxy принимает explicit loopback upstream через `proxy_pass`; исходный Host и
  client chain задаются `proxy_set_header`, WebSocket требует explicit Upgrade/Connection
  handling. `nginx -t` проверяет syntax и referenced files до `nginx -s reload`. Источники:
  [proxy module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html),
  [WebSocket proxying](https://nginx.org/en/docs/http/websocket.html),
  [command-line switches](https://nginx.org/en/docs/switches.html), проверено 2026-08-15.
  Confidence: high. RAG:✅.
- Certbot NGINX HTTP-01 требует публичный port 80 либо отдельный DNS validation path;
  `certbot renew --dry-run` проверяет renewal, а установленный timer/cron не следует дублировать.
  Active paths видны через `certbot certificates`; managed `/etc/letsencrypt` bytes нельзя
  удалять вручную. Источники:
  [Certbot NGINX instructions](https://certbot.eff.org/instructions?ws=nginx&os=ubuntufocal),
  [Certbot user guide](https://eff-certbot.readthedocs.io/en/stable/using.html), проверено
  2026-08-15. Confidence: medium: официальный selector остаётся legacy Ubuntu Focal URL и не
  сертифицирует отдельный Ubuntu 24.04 package path. RAG:✅.

### PostgreSQL, systemd and object storage

- PostgreSQL 17/18 `pg_dump -Fc` создаёт consistent logical archive; безопасный rehearsal
  восстанавливает его через `pg_restore` в отдельную database. `--clean`/`--create` несут
  destructive semantics; `--no-owner`, `--no-acl` и `--exit-on-error` должны быть осознанной
  частью isolated restore contract. Источники:
  [pg_dump](https://www.postgresql.org/docs/current/app-pgdump.html),
  [pg_restore](https://www.postgresql.org/docs/current/app-pgrestore.html),
  [SQL dump backup](https://www.postgresql.org/docs/current/backup-dump.html), проверено
  2026-08-15. Confidence: high. RAG:✅.
- systemd recurring job использует paired `.timer`/`.service`; `OnCalendar=`, `Persistent=true`
  и bounded randomization имеют разные semantics. Проверки: `systemd-analyze calendar`,
  `systemd-analyze verify --recursive-errors=yes` и `systemctl list-timers --all`. Failed
  unprefixed `ExecStart` делает service failed; retry policy не является universal backup default.
  Источники: [systemd.timer](https://www.freedesktop.org/software/systemd/man/latest/systemd.timer.html),
  [systemd.service](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html),
  [systemd-analyze](https://www.freedesktop.org/software/systemd/man/latest/systemd-analyze.html),
  проверено 2026-08-15. Confidence: high. RAG:✅.
- Community MinIO docs подтверждают: `mc mirror` переносит только latest object state и не
  сохраняет version history/version metadata; history-preserving recovery требует заранее
  настроенной bucket/site replication. Источники:
  [Community site-failure recovery](https://docs.min.io/community/minio-object-store/operations/data-recovery/recover-after-site-failure.html),
  [Community replication](https://docs.min.io/community/minio-object-store/reference/minio-mc/mc-replicate-add.html),
  проверено 2026-08-15. Confidence: medium: docs не дают exact compatibility certificate для
  закреплённого server release `RELEASE.2025-04-22T22-12-26Z` и конкретного будущего `mc`.
  RAG:✅.

## Design consequences

1. Current P12 может проверять syntax, templates, fixtures, redaction и source-derived command
   inventory локально; он не утверждает host/DNS/TLS/GitHub/registry/CI/runtime facts.
2. Evidence carriers становятся redacted templates/checklists. Заполненные receipts, timestamps,
   remote hashes, RPO/RTO и runtime verdict принадлежат отдельному operator rehearsal plan.
3. MinIO template обязан явно различать latest-state mirror и history-preserving replication.
   Exact pinned server/`mc` behavior проверяется разрушительной disposable fixture в будущем плане,
   а не выводится из AIStor documentation.
4. `PREPARATION_READY` означает только: scripts/docs/templates проходят локальные contract gates.
   Оно не является D45 production `READY`.

## Проверка (verification status)

- RAG: `perplexity-web search_advanced` ×9, primary-source constrained, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact Ubuntu 24.04 Certbot installation selector; full metadata
  preservation matrix pinned Community MinIO server + future `mc`; реальные environment values и
  runtime outcomes. Всё перечисленное назначено будущему operator rehearsal plan.
- Обходки/[POSSIBLE-DEFECT]: нет; AIStor-only facts не применены к Community MinIO.
