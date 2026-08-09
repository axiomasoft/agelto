# P12 Ubuntu deployment RAG — 2026-08-09

## Repo-grounded boundary

- Agelto и private AzGuard находятся в GitHub (`axiomasoft/agelto` и
  `axiomasoft/azguard-private`); D19 требует отдельный checkout и full-SHA lock каждого private
  package source. Текущие checkout/Make/Compose — authoring snapshot, а исполняемый P12 обязан
  потреблять terminal P1–P11 carriers. RAG:— (repo-grounded: Git remotes, D17/D19).
- D17 запрещает build-on-host и code/vendor bind mounts: production host pulls immutable app и
  Doctor artifacts одного source SHA. D20 хранит secrets в ignored file-backed carrier, D21
  оставляет Doctor one-off tool, D23 разводит liveness и active readiness. RAG:—
  (repo-grounded: decisions D17/D20/D21/D23).
- D9 требует clean clone, пустые volumes/DB и реальный admin/assets/docs/queue/storage/Doctor
  smoke; D42 требует green candidate-SHA CI evidence. RAG:— (repo-grounded: D9/D42).

## Official-source facts

### Ubuntu, Docker и host security

- Docker Engine официально поддерживает Ubuntu 24.04 LTS и 26.04 LTS и рекомендует production
  installation через apt repository. При публикации container ports Docker предупреждает, что
  UFW/firewalld rules могут обходиться; эффективные ограничения должны учитывать Docker chains,
  включая `DOCKER-USER`. Источник: [Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/),
  проверено 2026-08-09. Confidence: high. RAG:✅.
- Членство в `docker` group даёт root-level privileges; Docker daemon socket нельзя открывать как
  unauthenticated TCP endpoint. Источники:
  [Linux post-install](https://docs.docker.com/engine/install/linux-postinstall/),
  [Docker daemon security](https://docs.docker.com/engine/security/), проверено 2026-08-09.
  Confidence: high. RAG:✅.
- Ubuntu поддерживает modular `sshd_config.d`; перед restart конфигурацию надо проверить через
  `sshd -t`. Ubuntu рекомендует least privilege, restricted SSH users, firewall и automatic
  security updates. Источники:
  [OpenSSH server](https://documentation.ubuntu.com/server/how-to/security/openssh-server/),
  [User management](https://documentation.ubuntu.com/server/how-to/security/user-management/),
  [Firewall](https://documentation.ubuntu.com/server/how-to/security/firewalls/),
  [Automatic updates](https://documentation.ubuntu.com/server/how-to/software/automatic-updates/),
  проверено 2026-08-09. Confidence: high. RAG:✅.
- Default Docker `json-file` logs могут исчерпать disk; официальный post-install guide требует
  явной rotation либо logging driver с rotation. Источник:
  [Linux post-install](https://docs.docker.com/engine/install/linux-postinstall/), проверено
  2026-08-09. Confidence: high. RAG:✅.

### Git, ingress и TLS

- GitHub deploy key привязан к одному repository и read-only по умолчанию; для двух private
  repositories нужны отдельные key pairs/SSH aliases. GitHub публикует host fingerprints, которые
  следует сверять до записи `known_hosts`, а не доверять слепому `ssh-keyscan`. Источники:
  [Managing deploy keys](https://docs.github.com/en/enterprise-cloud@latest/authentication/connecting-to-github-with-ssh/managing-deploy-keys),
  [GitHub SSH fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints),
  проверено 2026-08-09. Confidence: high. RAG:✅.
- Certbot Nginx flow предполагает доступный HTTP endpoint либо DNS validation и предоставляет
  `certbot renew --dry-run` для проверки renewal path. Источник:
  [Certbot Nginx instructions](https://certbot.eff.org/instructions?os=ubuntufocal&ws=nginx),
  проверено 2026-08-09. Confidence: medium: страница официальная, но OS selector URL legacy;
  executor обязан повторно открыть current Ubuntu selector перед mutation. RAG:✅.
- Nginx reverse proxy по умолчанию не передаёт исходный `Host`; explicit proxy headers должны
  быть частью проверяемого config. Источник:
  [ngx_http_proxy_module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html), проверено
  2026-08-09. Confidence: high. RAG:✅.

### Backup и restore

- PostgreSQL 17 `pg_dump --format=custom` создаёт archive для `pg_restore`; факт создания dump не
  доказывает восстановимость. Источники:
  [pg_dump 17](https://www.postgresql.org/docs/17/app-pgdump.html),
  [pg_restore 17](https://www.postgresql.org/docs/17/app-pgrestore.html), проверено 2026-08-09.
  Confidence: high. RAG:✅.
- Docker volumes требуют отдельной backup/restore процедуры; container/image не является backup
  persistent data. Источник: [Docker volumes](https://docs.docker.com/engine/storage/volumes/),
  проверено 2026-08-09. Confidence: high. RAG:✅.
- `mc mirror` переносит текущие objects, но не version history/metadata; если retention опирается
  на versions, нужен version-aware replication/export, а не один mirror. Источник:
  [MinIO mc mirror](https://min.io/docs/minio/linux/reference/minio-mc/mc-mirror.html), проверено
  2026-08-09. Confidence: medium: official MinIO docs, exact terminal MinIO release ещё является
  выходом P1/P12 preflight. RAG:✅.

## Verdicts for P12 design

- Ubuntu 24.04 LTS — консервативный базовый host profile первого deploy; 26.04 LTS допускается
  только после отдельного полного rehearsal. Это внутренняя release policy поверх двух официально
  поддерживаемых Docker targets, не внешний факт.
- Host Nginx terminates TLS и проксирует только на loopback-bound app port. В Internet публикуются
  только SSH/HTTP/HTTPS; effective Docker firewall path проверяется отдельно от UFW prose.
- Deploy automation использует отдельные read-only GitHub deploy keys на main и AzGuard repos,
  отдельные aliases и pinned host fingerprint. Write credentials/agent forwarding не нужны.
- Backup scope обязан включать PostgreSQL, object/media data, encrypted recovery copy secrets и
  configuration/lock manifests. Redis/cache, checkout private vendor и rebuildable images не
  являются authoritative backup data. RPO/RTO/retention/location — owner-approved deployment
  profile; отсутствие значений блокирует go-live.

## Проверка (verification status)

- RAG: Web primary-source search ×4 batches OK; Docker, Ubuntu, GitHub, Nginx, Certbot,
  PostgreSQL и MinIO official docs проверены 2026-08-09.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: current exact Certbot install command и terminal image/database
  versions — P12.1/P12.3 повторяют official selector после terminal P1; deployment domain, registry,
  RPO/RTO/retention и off-host destination — owner-supplied production profile.
- Обходки/[POSSIBLE-DEFECT]: нет; `mc mirror` как version-aware backup явно отвергнут.
