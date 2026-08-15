# P12.1 host carrier evidence

## Current official evidence — 2026-08-15

- Docker supports Ubuntu 24.04 and prescribes installation through its official apt repository;
  the convenience script is for testing/development, not this host carrier. Source: [Install Docker
  Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/). Confidence: high.
- Docker-published ports can bypass UFW. Docker documents `DOCKER-USER` as the place for user
  filtering before Docker's chains; an active UFW status alone is therefore insufficient. Sources:
  [Packet filtering and firewalls](https://docs.docker.com/engine/network/packet-filtering-firewalls/)
  and [Docker with iptables](https://docs.docker.com/engine/network/firewall-iptables/). Confidence: high.
- Docker treats membership in the `docker` group as root-level privilege. The daemon remains
  Unix-socket-only and no TCP API listener is accepted. Source: [Linux post-installation steps](https://docs.docker.com/engine/install/linux-postinstall/).
  Confidence: high.
- Ubuntu recommends `sshd_config.d` snippets and `sshd -t` before reloading a remotely reachable
  SSH daemon. Source: [OpenSSH server](https://documentation.ubuntu.com/server/how-to/security/openssh-server/).
  Confidence: high.
- Docker's `local` logging driver performs rotation by default; the checked daemon config selects
  it explicitly. Source: [Configure logging drivers](https://docs.docker.com/engine/logging/configure/).
  Confidence: high.

## Implemented carrier

- Product commit: `c8145ee5` (`feat(host): подготовить Ubuntu host carrier (P12.1)`).
- `docker/scripts/host-provision.sh` is a small Bash helper: `check` validates Ubuntu/profile/source
  safety without mutation; `apply` performs only repeatable Docker/deploy-user setup after an
  explicit confirmation.
- `docs/04_Operators/production/ubuntu-host.md` holds the human-reviewable SSH two-session cutover,
  UFW/`DOCKER-USER` commands, reboot proof and operating boundary.
- `docker/scripts/host-preflight.sh` proves effective SSH/UFW/Docker socket/logging/listener/time
  state after those documented host steps. It prints no profile values.
- `docker-compose.prod.yml` now binds the app to
  `${APP_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8080}`; PostgreSQL, Redis and MinIO were already
  loopback-bound.

## Local production simulation — 2026-08-15

- `bash docker/tests/host-contract.sh` — GREEN: shell/JSON/SSH contract, unsafe profile fixtures,
  wrong-OS/unsafe-SSH/port/Docker-TCP/no-mutation guards and redaction checks.
- A distinct `agelto_p121_local` Compose project started from empty project volumes with
  `agelto/app:p4-73cdb9c48504` and matching Doctor image. All six services became healthy; both
  container-local `/up` and `http://127.0.0.1:45136/up` returned success.
- The listener inventory confirmed app `127.0.0.1:45136`, PostgreSQL `127.0.0.1:45132`, Redis
  `127.0.0.1:45133`, and MinIO `127.0.0.1:45134/45135`; no test port was publicly bound.
- The project was stopped with its own empty volumes removed. Existing user Compose projects,
  containers, volumes and repository uploads were not touched.

## Accepted deviation and boundary

No disposable Ubuntu server is available. By owner direction, the local production simulation is
accepted as P12.1 evidence and does **not** require a later repeat merely to close this item. It is
not represented as proof of fresh-VM state, a real second external SSH login, public firewall reachability,
or reboot persistence. Those are distinct facts for the final P12.7 rehearsal, not an open P12.1
retest.

## Проверка (verification status)

- RAG: official Docker ×4 and Ubuntu ×1, verified 2026-08-15; all sources primary.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: nothing required to close the local P12.1 carrier.
- Обходки/[POSSIBLE-DEFECT]: local host simulation is an accepted, explicitly bounded deviation;
  it is not a claim about a remote Ubuntu host.
