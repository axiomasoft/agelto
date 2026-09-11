# Roadmap исполнения — 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION

**Обновлён:** 2026-09-10 · **Соответствует design pass:** 2/2 + audit #2 carrier repair

Roadmap не переопределяет Routing §3, owner gates или per-item close. Ни один item не
стартует до GREEN `$ task:plan-audit 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION design`;
после аудита исполнитель берёт только launch-block, который текущий `handoff.md`
объявил semantic Next.

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Предусловие / гейт | Почему так |
|:--|:--|:--|:--|:--|:--|
| P0.1 | solo | plan-run | implementation/high | design audit GREEN; test DB preflight | Data-integrity migration и existing/fresh schema proof — отдельная rollback boundary |
| P1.1–P1.2 | B-P1-CRAWL | plan-run series | implementation/high | terminal/audited P0 | Adjacent producer→consumer на общих `FreshRunProcessor`/cursor fixtures и validation |
| P1.3–P1.4 | B-P1-RECOVERY | plan-run series | implementation/high | terminal P1.2 | Общий run/cursor/PostgreSQL harness: scope/checkpoint перед execution lease |
| P2.1–P2.2 | B-P2-SNAPSHOT | plan-run series | implementation/high | terminal/audited P1 | Typed completeness — непосредственный вход current-set reconcile в том же detail harness |
| P2.3 | solo | plan-run | frontier/high | terminal P2.2; D8/D11 | Open external B2 contract и owner/live boundary несовместимы с deterministic snapshot batch |
| P3.1–P3.3 | B-P3-STORAGE | plan-run series | implementation/high | terminal/audited P2 | Один due-download/storage fault matrix для source, archive и representations |
| P3.4 | solo | plan-run | implementation/high | terminal P3.3 | Cross-stage DB→queue recovery и state-machine review — отдельная fault boundary |
| P3.5 | solo | plan-run | implementation/high | terminal P3.4; Redis/Horizon worker available | Real-worker PID/signal/time-budget proof и config surface не смешиваются с state repair |
| P4.1 | solo | plan-run | frontier/high | terminal/audited P3; digest-bound owner authorization/scope | Live A1/B2 traffic и conditional D8 remediation требуют своего authority boundary |
| P4.2 | solo | plan-run | frontier/xhigh | terminal P4.1 + A1/B2 machine evidence; fresh independent actor/context | Offline adversarial acceptance не совмещается с live traffic и product repair |
| P4.3 | solo | plan-run | frontier/xhigh | terminal P4.1; GREEN P4.2; digest-bound owner authorization; ≥72 h | D10 authority и calendar boundary для controlled-production GO/NO-GO |
| P4.4 | solo | plan-exec | implementation/medium | terminal P4.3 verdict | Детерминированный post-gate B3 slice; не меняет P4.3 verdict |

## Launch-block'и групп

Каждый блок ниже — будущий routed carrier, а не разрешение обойти текущий handoff,
dependency, audit или owner gate. `Context: continue-root` означает, что запущенный root
ведёт весь указанный batch; P4.2 — явная `cold-start-root: whole-plan-independent-audit`
граница со свежим provider session/context evidence.

### solo P0.1 — партиции

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Закрыть A8 additive migration и PostgreSQL catalog regressions |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P0.1'
```

### B-P1-CRAWL — fresh stop и hard-cap

| Параметр | Значение |
|:--|:--|
| Batch | B-P1-CRAWL |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Исполнить P1.1–P1.2 одной adjacent серией |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P1.1-P1.2'
```

### B-P1-RECOVERY — scope/checkpoint и lease

| Параметр | Значение |
|:--|:--|
| Batch | B-P1-RECOVERY |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Исполнить P1.3–P1.4 на общем PostgreSQL recovery harness |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P1.3-P1.4'
```

### B-P2-SNAPSHOT — completeness и current projection

| Параметр | Значение |
|:--|:--|
| Batch | B-P2-SNAPSHOT |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Исполнить P2.1–P2.2 как typed snapshot producer→consumer |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P2.1-P2.2'
```

### solo P2.3 — B2 protocol

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | high |
| Context | continue-root |
| Суть | Запечатать D8 live protocol без сетевых запросов |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P2.3'
```

### B-P3-STORAGE — retry и storage truth

| Параметр | Значение |
|:--|:--|
| Batch | B-P3-STORAGE |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Исполнить P3.1–P3.3 на общей fault-injection matrix |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P3.1-P3.3'
```

### solo P3.4 — pipeline recovery

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Замкнуть retryable analyses и DB→queue fault windows |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P3.4'
```

### solo P3.5 — worker budgets

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high |
| Context | continue-root |
| Суть | Сверить timeouts/lease/retry_after и доказать real-worker recovery |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P3.5'
```

### solo P4.1 — live A1/B2

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | high |
| Context | continue-root |
| Суть | Исполнить owner-authorized A1/B2 probes и branch-required bounded revalidation |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P4.1'
```

### solo P4.2 — independent acceptance

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | xhigh |
| Context | cold-start-root: whole-plan-independent-audit |
| Суть | Свежим actor/context offline переиграть A1–A9/B1/B2/NEW evidence |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P4.2'
```

### solo P4.3 — controlled production

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | xhigh |
| Context | continue-root |
| Суть | Провести owner-authorized 72-hour GO/NO-GO gate |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access '$ task:plan-run 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P4.3'
```

### solo P4.4 — immutable raw evidence

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | medium |
| Context | continue-root |
| Суть | Закрыть post-gate B3 immutable addressing без пересмотра P4.3 |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-exec 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION P4.4'
```

## Гейты владельца и capabilities

| Где | Что требуется | Блокирует |
|:--|:--|:--|
| P4.1 | `artifacts/P4-live-authorization.json` + `artifacts/P4-live-scope.json`: registry/procurements, request ceiling, window | Любой live EIS request, A1/B2 evidence и conditional remediation |
| P4.2 | Terminal P4.1 state + digest-bound A1/B2 machine evidence; fresh actor/context, отличные от implementation и P4.1 | Offline independent verdict и P4.3 |
| P4.3 | `artifacts/P4-controlled-production-authorization.json`: environment/profile/scope/ceilings/stop action | Controlled-production traffic и 72-hour окно |
| P3.5 | Actual Redis/Horizon worker и signal control | Real-worker timeout/recovery closure |

P4.3 может выдать controlled-production verdict при ещё открытом P4.4 по D5; terminal
plan archive ждёт P4.4 и финальный phase audit/close.
