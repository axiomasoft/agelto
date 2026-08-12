# Roadmap исполнения — 2026.08.09-RELEASE-READINESS

**Обновлён:** 2026-08-11 · **Соответствует plan.md:** Layout v2, 80 items: 75-item finish + remediations P0.7/P1.6/P1.7/P2.7/P4.6; финальный reopen P4 зафиксирован D72

Roadmap пересобран после повторного full `finish` D52 и адресной remediation F1–F12; D55 закрывает
audit-loop после трёх заходов и открывает execution напрямую с P0.0. `solo` оставлен для owner-gate,
смены route/review и frontier freeze; совместимые последовательные manual items объединяются в
batch и всё равно закрываются каждый своим commit/validation/handoff.

## Матрица решений по группировке

| Фаза | Кандидаты | Решение | Route/review | Причина |
|:--|:--|:--|:--|:--|
| P0 | P0.0–P0.7 | terminal | — | фаза закрыта после P0.7 remediation D62; следующий исполнимый item — P1.1 |
| P1 | P1.1–P1.7 | solo P1.7 | implementation/high · full | F6–F10 второго RED-аудита; P2 закрыта до terminal P1.7, close и fresh-context audit P1 |
| P2 | P2.1–P2.7 | solo P2.7 | implementation/high · full | один последовательный audit-defined release carrier; evidence reconcile допустим только после GREEN product anchor |
| P3 | P3.1–P3.5 | B3-READINESS | implementation/high · full | diagnosis→repair→readiness→E2E→recovery использует один runtime context; root-cause escalation останавливает серию |
| P4 | P4.1–P4.6 | terminal | — | фаза закрыта после P4.6 remediation D72; следующий исполнимый item — P5.1 |
| P5 | P5.1–P5.6 | solo P5.6 | implementation/high · full | owner D76: один cross-repo AUDIT-CAP carrier до 0/0 и direct close без Audit 3 |
| P6–P12 | текущие solo | solo | как в §3 Routing | требуются owner/external gates, frontier freeze либо отдельный риск-контекст; кандидаты на следующие batch-проходы рассматриваются по фактическим terminal inputs |

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|:--|:--|:--|:--|:--|:--|
| P0.0 | solo | `task:plan-exec … P0.0` | implementation/medium | — | prerequisite: materialize stdlib citation verifier before all consumers |
| P0.1 | solo | `task:plan-run … P0.1` | implementation/high | — | широкий immutable repo inventory; отдельный evidence close |
| P0.2 | solo | `task:plan-run … P0.2` | implementation/high | доступ к 3 reference trees | отдельный чужой-tree snapshot, не смешивать с P0.1 |
| P0.3 | closed | — | implementation/high | — | 🟢 MCP replay D56; item commit `751d72dd` |
| P0.4 | B0-RAG | `task:plan-run … P0.4-P0.5` | implementation/high | — | consumes terminal P0.3 MCP findings |
| P0.5 | B0-RAG | ↑ | implementation/high | — | consumes P0.4 findings in-session |
| P0.6 | solo | `task:plan-run … P0.6` | frontier/high | утверждение frozen contracts | cross-track synthesis меняет tier и запечатывает решения |
| P0.7 | solo | `task:plan-exec … P0.7` | implementation/medium | — | один детерминированный remediation slice F1–F5; P1 остаётся закрыта до GREEN-аудита P0 |
| P1.1 | closed | — | implementation/medium | — | terminal provenance сохранён; exact lint/tree correction anchors выполняет P1.7 |
| P1.2 | closed | — | implementation/high | — | terminal; P1.7 сохраняет actual/prescribed route и exact lint/tree anchors |
| P1.3 | closed | — | implementation/high | — | terminal immutable runtime evidence |
| P1.4 | closed-deviations | — | implementation/high | — | terminal Vite topology; docs deviation входит в P1.6 |
| P1.5 | closed-deviations | — | implementation/high | — | RED-аудит F1–F5 породил D64/P1.6 |
| P1.6 | closed | — | implementation/high | — | `44a334ac` не является full Validation anchor; correction на новый clean P1.7 product commit обязательна |
| P1.7 | solo | `task:plan-run … P1.7` | implementation/high | — | один audit-defined slice F6–F10: full carrier, expired alias removal и append-only anchors |
| P2.1 | closed-evidence-remediation | — | implementation/high | — | original vendor boundary terminal; P2.7 replaces optional acceptance and corrects evidence |
| P2.2 | closed-evidence-remediation | — | implementation/high | — | original secret carrier terminal; P2.7 closes hostile path/leakage boundary |
| P2.3 | closed-evidence-remediation | — | implementation/high | — | original preflight terminal; P2.7 repairs negative fixture and replays exact exits |
| P2.4 | closed-evidence-remediation | — | implementation/high | — | original bootstrap terminal; P2.7 adds mandatory fresh-clone real proof |
| P2.5 | closed-evidence-remediation | — | implementation/high | — | original seed/admin product + `d7a4be65`; P2.7 adds real suites and exact anchors |
| P2.6 | closed-evidence-remediation | — | implementation/high | — | original Doctor product + `189dea38`; P2.7 closes diagnostics/image/evidence gaps |
| P2.7 | solo | `task:plan-run … P2.7` | implementation/high | — | финальный audit-defined F1–F8 slice; real Docker/DB/quality gates, затем close→audit |
| P3.1 | B3-READINESS | `task:plan-run … P3.1-P3.5` | implementation/high | — | diagnosis→repair→proof; stop on escalation |
| P3.2 | B3-READINESS | ↑ | implementation/high | — | permitted repair from P3.1 evidence |
| P3.3 | B3-READINESS | ↑ | implementation/high | — | readiness follows repair |
| P3.4 | B3-READINESS | ↑ | implementation/high | — | E2E follows readiness |
| P3.5 | B3-READINESS | ↑ | implementation/high | — | terminal recovery proof |
| P4.1 | solo | `task:plan-run … P4.1` | frontier/high | frozen threat model | public security contract freeze changes tier |
| P4.2 | solo | `task:plan-run … P4.2` | implementation/high | — | private docs build/image supply-chain |
| P4.3 | solo | `task:plan-run … P4.3` | implementation/high | — | path/MIME/cache response security boundary |
| P4.4 | solo | `task:plan-run … P4.4` | implementation/high | — | package/application authorization integration |
| P4.5 | solo | `task:plan-run … P4.5` | implementation/high | — | terminal adversarial HTTP/browser/image proof |
| P4.6 | closed | — | implementation/high | — | terminal product `d9f025f3`: active SVG rejected, self-contained clean candidate и exact browser/image proof GREEN; append-only evidence reconciled |
| P5.1 | solo | `task:plan-run … P5.1` | frontier/high | contract reconcile | terminal upstream may supersede proxy design |
| P5.2 | solo | `task:plan-run … P5.2` | implementation/high | — | settings schema/worker freshness mutation |
| P5.3 | solo | `task:plan-run … P5.3` | implementation/high | — | egress precedence/health correctness boundary |
| P5.4 | solo | `task:plan-run … P5.4` | implementation/high | — | secret-safe authorized settings UI |
| P5.5 | solo | `task:plan-run … P5.5` | implementation/high | — | terminal no-fallback/health/tooling proof |
| P5.6 | solo | `task:plan-run … P5.6` | implementation/high | D76 | final source/product/projection remediation; два repository commits, 0 deviations, direct close P5 без Audit 3 |
| P6.1 | solo | `task:plan-run … P6.1` | frontier/high | contract reconcile | terminal upstream may supersede format/archive design |
| P6.2 | solo | `task:plan-run … P6.2` | implementation/high | — | typed registry/result/reason contract |
| P6.3 | solo | `task:plan-run … P6.3` | implementation/high | — | hostile archive resource/path boundary |
| P6.4 | solo | `task:plan-run … P6.4` | implementation/high | — | transactional child persistence/dispatch |
| P6.5 | solo | `task:plan-run … P6.5` | implementation/high | — | concurrency/retry/adversarial proof |
| P6.6 | solo | `task:plan-exec … P6.6` | implementation/medium | — | deterministic terminal docs/contract gate |
| P7.1 | solo | `task:plan-run … P7.1` | frontier/high | taxonomy/schema freeze | public prompt/schema contract freeze |
| P7.2 | solo | `task:plan-run … P7.2` | implementation/high | — | schema migration/backfill/index correctness |
| P7.3 | solo | `task:plan-run … P7.3` | implementation/high | — | provider schema/prompt/gold contract |
| P7.4 | solo | `task:plan-run … P7.4` | implementation/high | — | idempotent analysis persistence/dispatch |
| P7.5 | solo | `task:plan-exec … P7.5` | implementation/medium | — | localized read-only projection, light review |
| P7.6 | solo | `task:plan-exec … P7.6` | implementation/medium | — | terminal deterministic contract/full-suite gate |
| P8.1 | solo | `task:plan-run … P8.1` | frontier/high | adapter/applicability freeze | cross-source contract reconciliation |
| P8.2 | solo | `task:plan-run … P8.2` | implementation/high | — | typed platform runtime/config boundary |
| P8.3 | solo | `task:plan-run … P8.3` | implementation/high | — | lossless schema/index migration |
| P8.4 | solo | `task:plan-run … P8.4` | implementation/high | — | precedence/race/seed/auth correctness |
| P8.5 | solo | `task:plan-exec … P8.5` | implementation/medium | — | terminal deterministic extension gate |
| P9.1 | solo | `task:plan-run … P9.1` | frontier/high | navigation/presentation freeze | cross-package UI contract freeze |
| P9.2 | solo | `task:plan-run … P9.2` | implementation/high | — | package/public labels and identity |
| P9.3 | solo | `task:plan-run … P9.3` | implementation/high | — | all-resource form schema/security sweep |
| P9.4 | solo | `task:plan-run … P9.4` | implementation/high | — | all-table responsive/auth/query sweep |
| P9.5 | solo | `task:plan-run … P9.5` | implementation/high | — | wide runtime role/route surface |
| P9.6 | solo | `task:plan-run … P9.6` | implementation/high | — | terminal browser authorization/responsive proof |
| P10.1 | solo | `task:plan-run … P10.1` | frontier/high | tooling branch freeze | immutable baseline and Rector decision |
| P10.2 | solo | `task:plan-run … P10.2` | implementation/high | — | security audit evidence carrier |
| P10.3 | solo | `task:plan-run … P10.3` | implementation/high | — | architecture/naming/debt audit carrier |
| P10.4 | solo | `task:plan-run … P10.4` | implementation/high | — | production-readiness/data audit carrier |
| P10.5 | solo | `task:plan-run … P10.5` | implementation/high | remediation disposition | large vertical remediation after frozen matrix |
| P10.6 | solo | `task:plan-run … P10.6` | implementation/high | — | terminal quality/static/docs gate |
| P11.1 | solo | `task:plan-run … P11.1` | frontier/high | Pest/CI contract freeze | measured baseline freezes D40–D42 choices |
| P11.2 | solo | `task:plan-run … P11.2` | implementation/high | — | TIA/concurrency/cache command mutation |
| P11.3 | solo | `task:plan-run … P11.3` | implementation/high | — | DB/filesystem/agent isolation boundary |
| P11.4 | solo | `task:plan-run … P11.4` | implementation/high | — | all-domain risk matrix and regression suites |
| P11.5 | solo | `task:plan-run … P11.5` | implementation/high | CI/private credentials | supply-chain/security workflow mutation |
| P11.6 | solo | `task:plan-run … P11.6` | implementation/high | live candidate CI | terminal performance/coverage/flake proof |
| P12.1 | solo | `task:plan-run … P12.1` | implementation/high | fresh Ubuntu host access | external host/security mutation |
| P12.2 | solo | `task:plan-run … P12.2` | implementation/high | GitHub key enrollment | production credential/source boundary |
| P12.3 | solo | `task:plan-run … P12.3` | implementation/high | approve RPO/RTO/schedule/retention/destination/alert endpoint | pre-deploy ingress/recovery config and fixture gates; no application evidence |
| P12.4 | solo | `task:plan-run … P12.4` | implementation/high | registry/DNS/host access | first production mutation plus live HTTPS/readiness/Doctor/Livewire proof |
| P12.5 | solo | `task:plan-run … P12.5` | implementation/high | rotation apply and destructive restore stay owner-guarded | post-deploy scheduled backup/retention/watchdog/alert/restore proof, queue/release and D20 rotation |
| P12.6 | solo | `task:plan-run … P12.6` | implementation/high | — | cross-carrier final command/docs verification |
| P12.7 | solo | `task:plan-run … P12.7` | frontier/xhigh | final external rehearsal access | irreversible cross-plan `READY|NOT_READY` verdict |

## Следующий запуск

**Next:** manual: frontier/high

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue-root |
| Суть | Сверить ProxyResolver/profile/scopes/health и заморозить P5 contract. |
| Команда | — |

```text
$ task:plan-run 2026.08.09-RELEASE-READINESS P5.1
```

### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P5.1'
```

## Гейты владельца (сводно)

| Где | Что утверждает/предоставляет | Блокирует |
|:--|:--|:--|
| До P0.2 | read-only access к Sova, Vintera/CMS и Dterema snapshots | P0.2 |
| P0.6 | frozen inter-phase contracts | P1 и далее |
| P2.1/P2.5 | private repository access и initial-admin identity | P2.1/P2.5 |
| P4.1/P7.1/P8.1/P9.1/P10.1/P11.1 | security/schema/adapter/UI/tooling/test freeze artifacts | соответствующую implementation tail фазы |
| P12.1–P12.4 | fresh host, GitHub enrollment, DNS/registry/off-host credentials без передачи secret values в план | P12.1–P12.4 |
| P12.3 | RPO/RTO/schedule/max-age/retention/destination/key-custodian/disk thresholds и alert endpoint file | P12.3–P12.7 |
| P12.5 | разрешение staged credential apply/rollback на rehearsal stack | P12.5–P12.7 |
| P12.7 | доступ к coherent external rehearsal; verdict не утверждается вручную | финальный READY proof |
