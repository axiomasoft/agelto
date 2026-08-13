# Roadmap исполнения — 2026.08.09-RELEASE-READINESS

<!-- Живая execution-карта; не переопределяет Routing §3, owner gates или item contracts. -->

**Обновлён:** 2026-08-13 · **Соответствует plan.md:** migration-version 0.1.12 · 13 фаз · 84 items · D83

## Правило группировки

- Terminal items свёрнуты по фазам; их исторические route и evidence остаются в §3, state и journal.
- Ready work не пересекает phase audit/close boundary. Current P9 tail остаётся `solo`: каждый item
  меняет общий Filament surface, потребляет terminal predecessor и имеет собственный validation gate.
- P10–P12 остаются `solo` из-за отдельных audit/security/external-host boundaries и owner gates.
- Любая новая группировка использует максимум route/review и union capabilities из §3; roadmap не
  меняет Exec или Review.

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|:--|:--|:--|:--|:--|:--|
| P0.0–P0.7 | closed | — | §3 Routing | — | 8/8 `🟢 Done` |
| P1.1–P1.7 | closed | — | §3 Routing | — | 7/7 `🟢 Done` |
| P2.1–P2.7 | closed | — | §3 Routing | — | 7/7 `🟢 Done` |
| P3.1–P3.5 | closed | — | §3 Routing | — | 5/5 `🟢 Done` |
| P4.1–P4.6 | closed-deviations | — | §3 Routing | — | 4 `🟢`, 2 `🟠`; точные deviations принадлежат state/journal |
| P5.1–P5.6 | closed | — | §3 Routing | — | 6/6 `🟢 Done` |
| P6.1–P6.7 | closed-deviations | — | §3 Routing | — | 7/7 `🟠`; D79/closure сохраняют принятый evidence status |
| P7.1–P7.6 | closed | — | §3 Routing | — | 6/6 `🟢 Done` |
| P8.1–P8.7 | closed | — | §3 Routing | — | 7/7 `🟢 Done`; direct close по D81 |
| P9.1–P9.6 | closed | — | §3 Routing | D84 direct close | 6/6 `🟢 Done`; GREEN audit accepted, self-induced stale fingerprint recorded for Swissknifeman follow-up |
| P10.1 | solo | `task:plan-run … P10.1` | frontier/high | terminal P9 + tooling branch freeze | immutable baseline и Rector decision |
| P10.2 | solo | `task:plan-run … P10.2` | implementation/high | terminal P10.1 | security audit evidence carrier |
| P10.3 | solo | `task:plan-run … P10.3` | implementation/high | terminal P10.2 | architecture/naming/debt audit carrier |
| P10.4 | solo | `task:plan-run … P10.4` | implementation/high | terminal P10.3 | production-readiness/data audit carrier |
| P10.5 | solo | `task:plan-run … P10.5` | implementation/high | approved disposition matrix | bounded vertical remediation |
| P10.6 | solo | `task:plan-run … P10.6` | implementation/high | terminal P10.5 | local quality/static/docs gate; затем phase audit |
| P11.1 | solo | `task:plan-run … P11.1` | frontier/high | terminal P10 + Pest/CI freeze | measured baseline and D40–D42 reconcile |
| P11.2 | solo | `task:plan-run … P11.2` | implementation/high | terminal P11.1 | TIA/concurrency/cache command mutation |
| P11.3 | solo | `task:plan-run … P11.3` | implementation/high | terminal P11.2 | DB/filesystem/agent isolation boundary |
| P11.4 | solo | `task:plan-run … P11.4` | implementation/high | terminal P11.3 | all-domain risk matrix and regressions |
| P11.5 | solo | `task:plan-run … P11.5` | implementation/high | CI/private credentials | hardened workflow and supply-chain gates |
| P11.6 | solo | `task:plan-run … P11.6` | implementation/high | live candidate CI | coverage/performance/flake proof; затем phase audit |
| P12.1 | solo | `task:plan-run … P12.1` | implementation/high | fresh Ubuntu host access | host provisioning/firewall/runtime |
| P12.2 | solo | `task:plan-run … P12.2` | implementation/high | GitHub key enrollment | repository/private-package source identity |
| P12.3 | solo | `task:plan-run … P12.3` | implementation/high | RPO/RTO/schedule/retention/destination/alert policy | ingress + recovery fixture gates |
| P12.4 | solo | `task:plan-run … P12.4` | implementation/high | registry/DNS/host access | first production mutation and live proof |
| P12.5 | solo | `task:plan-run … P12.5` | implementation/high | staged rotation/restore authorization | recurring backup/alert/restore and rollback proof |
| P12.6 | solo | `task:plan-run … P12.6` | implementation/high | terminal P12.5 | final command/docs reconcile |
| P12.7 | solo | `task:plan-run … P12.7` | frontier/xhigh | coherent external rehearsal | final `READY|NOT_READY` verdict |

## Следующий protocol gate

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P10.1

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | cold-start-root: owner-pause-no-resume |
| Суть | Зафиксировать immutable P10 tooling/dependency baseline до audits и remediation. |
| Команда | `$ task:plan-run 2026.08.09-RELEASE-READINESS P10.1` |

### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P10.1'
```

## Следующий execution

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | cold-start-root: owner-pause-no-resume |
| Суть | Снять immutable baseline P10.1 до read-only audit chain. |
| Команда | `$ task:plan-run 2026.08.09-RELEASE-READINESS P10.1` |

### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P10.1'
```

## Гейты владельца (сводно)

| Где | Что утверждает/предоставляет | Блокирует |
|:--|:--|:--|
| D83 audit | independent GREEN design verdict | P9.4 и весь remaining execution tail |
| P10.1/P11.1 | tooling и Pest/CI freeze artifacts | соответствующие implementation tails |
| P12.1–P12.4 | fresh host, GitHub enrollment, DNS/registry/off-host credentials без secret values в плане | P12.1–P12.4 |
| P12.3 | RPO/RTO/schedule/max-age/retention/destination/key-custodian/disk thresholds и alert endpoint file | P12.3–P12.7 |
| P12.5 | staged credential apply/rollback на rehearsal stack | P12.5–P12.7 |
| P12.7 | coherent external rehearsal access; verdict не утверждается вручную | final `READY|NOT_READY` proof |
