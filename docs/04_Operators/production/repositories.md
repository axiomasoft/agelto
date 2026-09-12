---
audience: operator
type: howto
status: living
---

# Production repository identities

На shared host **не запускайте `repositories:prepare` ниже**: helper перезаписывает `.ssh/config`
и `known_hosts` пользователя deploy и может сломать соседние identities. Используйте отдельный
Agelto key и repo-local `core.sshCommand` по [ручному сценарию](manual-deployment.md).
Bootstrap key можно оставить постоянным read-only key, не создавая ещё один без необходимости.
`vendor-sync.sh` читает pinned vendor по HTTPS: публичный repository не требует отдельного key;
private repository требует своего read-only key. Личный token и GitHub write access запрещены.

Run these steps as `root` on the P12.1-checked Ubuntu host. The ignored production profile remains
mode `0600`; complete its `AGELTO_REPOSITORY`, full `AGELTO_CANDIDATE_SHA`, checkout directory and
the current GitHub Ed25519 public-key body. Do not obtain that host key with `ssh-keyscan`: compare
the profile value with GitHub's published fingerprint first.

```bash
ssh-keygen -lf <(printf 'github.com ssh-ed25519 %s\n' "$(grep '^GITHUB_ED25519_HOST_KEY=' docker/host/production-profile | cut -d= -f2)") -E sha256
sudo make -f docker/Makefile repositories:prepare PROFILE=docker/host/production-profile
```

The reported fingerprint must be `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`. Enrol the
two printed public keys separately in the corresponding GitHub repositories as **read-only deploy
keys**. Never select write access, substitute a personal key/token, or enable agent forwarding.

After enrollment, run the resumable sync:

```bash
sudo make -f docker/Makefile repositories:sync PROFILE=docker/host/production-profile
```

The helper refuses an unconfirmed enrollment, wrong GitHub host key, a wrong/decorated origin,
anything dirty or untracked, a non-full SHA, and a branch checkout. It fetches the exact Agelto
candidate detached at the profile SHA and the D19 AzGuard lock SHA only under
`local/vendors/azguard`; then it reuses P2's vendor verifier. Its read-only test is a deliberately
failing `git push --dry-run`, so it does not mutate either repository.

Keep the public-key fingerprint, canonical origins and the two full SHAs in the redacted P12.2
evidence record. Never copy a private-key byte, `known_hosts` body or profile into that evidence.

GitHub documents that a deploy key is repository-specific and multiple repositories require separate
key pairs and aliases. It also publishes the Ed25519 fingerprint used here. See [Managing deploy
keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)
and [GitHub SSH fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).
