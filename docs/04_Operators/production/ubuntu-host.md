---
audience: operator
type: how-to
status: living
---

# Ubuntu 24.04 host bootstrap

This is a short, literal runbook for a fresh Ubuntu 24.04 `amd64` or `arm64` host. The helper
performs only repeatable setup; SSH cutover and network policy stay visible here, where they can be
reviewed before they affect access.

## 1. Create and check the profile

Put the deploy public key and root-only secret files on the server, fill the ignored profile, then
run the read-only check.

```bash
sudo make -f docker/Makefile host:check PROFILE=docker/host/production-profile
```

## 2. Install Docker and the deploy identity

The helper follows Docker's official Ubuntu apt-repository installation. It creates `deploy`, gives
it the supplied public key, installs the Docker Engine/Buildx/Compose plugin, and installs the
tracked daemon configuration. The `docker` group is root-equivalent privilege; only the `deploy`
account receives it.

```bash
sudo make -f docker/Makefile host:apply PROFILE=docker/host/production-profile CONFIRM=apply
docker version
docker compose version
```

## 3. Make SSH key-only safely

Keep the existing root session open. In a second terminal, prove a new key-only login as `deploy`.
Only after it succeeds install the fragment, validate it, and reload SSH (never restart it):

```bash
sudo install -m 0644 docker/host/sshd_config.d/90-agelto.conf /etc/ssh/sshd_config.d/90-agelto.conf
sudo sshd -t
sudo systemctl reload ssh
```

Confirm that root and password login now fail from a third session before closing the original one.

## 4. Firewall and Docker packets

Docker-published ports can bypass UFW. Do not publish application or data ports publicly: bind them
to `127.0.0.1`, keep public access to SSH/80/443 only, and add the filter before Docker rules.

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo iptables -N DOCKER-USER 2>/dev/null || true
sudo iptables -I DOCKER-USER 1 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
sudo iptables -I DOCKER-USER 2 -i "$(ip route show default | awk '{print $5; exit}')" -j DROP
sudo iptables -A DOCKER-USER -j RETURN
```

Persist those exact `DOCKER-USER` rules with the host's approved systemd/firewall mechanism, then
reboot and prove they return. P12.1 preflight rejects absent effective rules; UFW status alone is
not evidence.

## 5. Verify after reboot

```bash
sudo make -f docker/Makefile host:preflight PROFILE=docker/host/production-profile
ss -lnt
docker info --format '{{.LoggingDriver}}'
systemctl status apt-daily-upgrade.timer docker --no-pager
```

The daemon uses Docker's `local` logging driver with rotation. Unattended security upgrades are
enabled, but automatic reboot remains an operator decision; inspect `/var/run/reboot-required`.

## Evidence limitation

P12.1 is exercised on the local Ubuntu/Docker machine because no disposable server is available.
That validates scripts, Compose startup and local production topology, but does not claim a fresh
VM, external SSH login, public firewall, or reboot-persistence proof. Those host facts remain a
future server rehearsal, not a hidden retry requirement for this local evidence.
