# HANDOFF — 2026-08-15 — after P12.1

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P12.2

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation |
| Thinking | high — private source identity boundary |
| Context | continue-root |
| Суть | Выполнить P12.2: раздельные read-only Git identities и pinned private sources. |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P12.2'
```

**Done:** P12.1 product commit `c8145ee5` adds a concise Ubuntu 24.04 host carrier: Bash `check|apply|preflight`, closed ignored production profile, key-only SSH and Docker daemon templates, readable SSH/UFW/DOCKER-USER guide, and loopback-only production app bind. `bash docker/tests/host-contract.sh` and an empty-volume isolated Compose startup were green; evidence is in `artifacts/P12-host-evidence.md`.

**Remaining:** P12.2 establishes the two read-only Git identities and pinned clean source proof. A live CI run remains required before any release approval; it is not represented as green.

**Sources of truth:** `artifacts/P12-host-evidence.md` · `docker/scripts/host-provision.sh` · `docker/scripts/host-preflight.sh` · `docs/04_Operators/production/ubuntu-host.md` · `journal.jsonl` P12.1 terminal.

**Open risks:** P12.1 is `🟠 Done with deviations`: local Compose evidence is accepted for this item but does not claim a fresh Ubuntu VM, external second SSH login, public firewall reachability, or reboot persistence. Those are independent P12.7 rehearsal facts, not a P12.1 retest. P11.6 live CI remains owner-deferred and is required before release approval.

**Workarounds/Deferred/Open questions:** workarounds: accepted local host simulation bounded in P12.1 evidence · deferred: live CI for the eventual release candidate before release approval · open_questions: —.
