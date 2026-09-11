<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-11
inputs_sha256: f00b6d38c8cc776f1427afed01da05add0955abbc7d006034cffe6976184ffbd
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | — |
| Status | 🟠 Done with deviations |
| Last Updated | 2026-09-11 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Непрерывные исторические партиции | 1/1 | 🟢 Done |
| P1 | Полнота и конкурентность crawling | 4/4 | 🟢 Done |
| P2 | Достоверные snapshots и lifecycle документов | 3/3 | 🟢 Done |
| P3 | Recovery, storage и timeouts | 5/5 | 🟢 Done |
| P4 | Независимая приёмка и controlled-production gate | 3/4 | 🟠 Done with deviations |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Закрыть исторический разрыв партиций на fresh и existing install | 🟢 Done | 2026-09-10 |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Сделать fresh stop безопасным для суточной точности ЕИС | 🟢 Done | 2026-09-10 |
| P1.2 | Сохранять fresh hard-cap как resumable incomplete outcome | 🟢 Done | 2026-09-10 |
| P1.3 | Сохранить fresh scope и недренированный interval в backlog/delta recovery | 🟢 Done | 2026-09-10 |
| P1.4 | Исключить concurrent execution одного backlog/delta run | 🟢 Done | 2026-09-11 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Различить complete/incomplete/failed documents snapshot и защитить archive children | 🟢 Done | 2026-09-11 |
| P2.2 | Синхронизировать current items и parties только по complete common snapshot | 🟢 Done | 2026-09-11 |
| P2.3 | Запечатать B2 как условный контракт с live-verification branch | 🟢 Done | 2026-09-11 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Дать due Discovered документам единый retry route и общий detail backoff gate | 🟢 Done | 2026-09-11 |
| P3.2 | Сделать source/archive object write условием успешной версии | 🟢 Done | 2026-09-11 |
| P3.3 | Применить storage truth contract ко всем prepared representations | 🟢 Done | 2026-09-11 |
| P3.4 | Замкнуть retryable analyses и потерянные DB→queue continuations | 🟢 Done | 2026-09-11 |
| P3.5 | Согласовать operation budget, worker timeout, retry_after, lease и shutdown grace | 🟢 Done | 2026-09-11 |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Выполнить live A1/B2 verification и закрыть условную ветвь | 🟢 Done | 2026-09-11 |
| P4.2 | Провести независимую adversarial приёмку A/NEW/B1/B2 | 🟢 Done | 2026-09-11 |
| P4.3 | Завершить controlled-production gate в owner-directed local-only scope | 🟠 Done with deviations | 2026-09-11 |
| P4.4 | Сделать raw CSV/HTML evidence неизменяемым и fetch-addressable | 🟢 Done | 2026-09-11 |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-09-10 | gpt-5.6-sol/high | plan-design · in-progress · Создан новый Layout v2 plan: 5 полностью детализированных фаз, 17 items, design pass 1/2; следующий терминальный шаг — plan-design finish. |
| 2026-09-10 | gpt-5.6-sol/high | plan-design · in-progress · Design pass 2/2 finish: reconciled D2–D4 via D7–D9, finite Required Reads, P4 ordering and audit-ready roadmap; 17 items ready for design audit. |
| 2026-09-10 | gpt-5.6-sol/high | plan-design · in-progress · Finish post-sweep: D10 supersedes stale D6 prerequisite wording; P4.3 now requires resolved D8/P4.1, GREEN P4.2 and durable owner authority. |
| 2026-09-10 | frontier/xhigh | plan-audit · audit-red · audit-fixer; design audit #1; PROMOTE-CANDIDATE: plan-lint green while 16 future item bundles are statically unresolvable; verdict RED |
| 2026-09-10 | frontier/high | plan-design · in-progress · Design audit #1 repair: explicit Inputs, corrected D8/D10 ownership, P4.1 A1/B2 branches, sealed offline P4.2 evidence/Files and knowledge layers; return to one design audit. |
| 2026-09-10 | gpt-5.6-sol/xhigh | plan-audit · audit-red · design audit #2; candidate 328a504932c9; RED: DA2-F1 missing assurance, F2 P0 closure unbound, F3 P4.2 context conflict, F4 new Routing groups unmarked |
| 2026-09-10 | gpt-5.6-sol/high | plan-design · in-progress · Design audit #2 repair: DA2-F1–F4 closed in typed phase assurance, terminal P0 dependency, P4.2 cold-start independence, and explicit Routing semantics; return to one independent design audit. |
| 2026-09-10 | gpt-5.6-sol/xhigh | plan-audit · audit-green · design audit #3; candidate 3e0f01e52e19; GREEN: DA2-F1–DA2-F4 closed, P0.1 ALLOW; next plan-run P0.1 |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P0.1 started: route, batch admission, five-carrier context, contract and split evidence anchor are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P0.1 closed green: rolling 12-month historical-to-premake coverage, marker-safe rollback, catalog continuity check; migration, partition suites, crawler/procurement regression, Pint, PHPStan and diff check are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P0.1 closure projection repair: add mandatory write-site codes without product mutation. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P0.1 closed green: rolling 12-month historical-to-premake coverage, marker-safe rollback, catalog continuity check; migration, partition suites, crawler/procurement regression, Pint, PHPStan and diff check are green. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P0.1 closure formatting repair. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P0.1 closed green: rolling 12-month historical-to-premake coverage, marker-safe rollback and catalog continuity check are green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.1 started: route, B-P1-CRAWL admission, five-carrier context and bounded plan-carrier repair are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.1 closed green: explicit day precision, safe same-day boundary and source-exhausted stop; mapper/crawler regressions, quality and review are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.2 started: P1.1 terminal dependency, batch suffix admission, five-carrier context and split evidence anchor are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.2 closed green: hard-cap is resumable running checkpoint with one continuation; processor/job regressions, quality and review are green. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.1 closure projection repair: add required write-site codes without product mutation. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.1 closed green: product and validation remain green. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.2 closure projection repair: add required write-site codes without product mutation. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.2 closed green: product and validation remain green. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.1 closure formatting repair. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.1 closed green: product and validation remain green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.2 closure formatting repair. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · P1.2 closed green: product and validation remain green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · in-progress · P1.3 started: implementation/high route, B-P1-RECOVERY admission, five-carrier context and split evidence anchor are green; generated missing P1.4 bundle before admission. |
| 2026-09-10 | gpt-5.6-terra/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:28 |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P1.4 started: implementation/high route, fresh v2 context and B-P1-RECOVERY ready-subset admission are green; stale generated bundle was rebuilt before admission. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P1.4 continued in the current owner session after RED repair; route rebound before terminal evidence. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · P1.4 closed green: DB-clock lease/fencing, real two-process backlog/delta regressions, rollback, quality and repeat independent review are green. DocsIntegrity baseline is external. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P1.4 closure projection formatting repair. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · P1.4 closed green: product and validation remain green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P2.1 started: implementation/high route, B-P2-SNAPSHOT ready-subset admission, five-carrier context and split evidence anchor are green. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:35 |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P2.2 admitted: complete common snapshot will gate one atomic current-projection reconcile; product/closure evidence anchors split. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:37 |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · in-progress · P2.3 started: frontier/high route, solo admission, immutable five-carrier context and split product/closure evidence anchor are green; no live EIS traffic is authorized. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · decision-pending · note сокращена; полный текст: journal.jsonl:39 |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · closed-green · P2.3 closed green: D8 fail-closed live protocol, corrected uid/version wording and current-behavior regression. 16 tests / 52 assertions, diff check and D8 grep green; application target hashes unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | codex | plan-run · in-progress · P3.1: начат due download retry route и общий detail backoff gate. |
| 2026-09-11 | codex | plan-run · closed-green · P3.1 closed green: due Discovered downloads have one atomic retry owner and future detail backoff gates every refetch trigger. Targeted tests, Pint, PHPStan and diff check are green. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | codex | plan-run · in-progress · P3.1 closure projection repair: restore a distinct write-site line and semantic P3.2–P3.3 handoff. |
| 2026-09-11 | codex | plan-run · closed-green · P3.1 closed green: product validation remains green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | codex | plan-run · in-progress · P3.2: начата truthful source/archive object finalization. |
| 2026-09-11 | codex | plan-run · closed-green · P3.2 closed green: object write is checked before transactional source finalization; same-hash retries heal a missing object without duplicate version or event.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | codex | plan-run · in-progress · P3.3: shared checked representation writer/finalizer integrated; fault-injection coverage remains to close. |
| 2026-09-11 | codex | plan-run · closed-green · P3.3 closed green: one checked writer/finalizer now guards every prepared representation; false writes and storage exceptions leave no ready row.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | codex | plan-run · in-progress · P3.4 started: implementation/high route, solo admission, immutable five-carrier context and split product/closure evidence anchor are green. |
| 2026-09-11 | codex | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:50 |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P3.5 started: implementation/high route, solo admission, five-carrier context and split product/closure evidence anchor are green. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · in-progress · P3.5 resumed: implementation/high route re-bound after inherited in-progress attempt; fresh bundle/state, admission and contract gates are green. |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:53 |
| 2026-09-11 | gpt-5.6-terra/high | plan-run · closed-green · P3 closed green: storage/recovery/timeout evidence is green; phase assurance selects direct close without an audit.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · in-progress · P4.1 admitted after explicit repository-owner authorization: finite A1/B2 live scope, immutable five-carrier context, frontier/high route and split evidence anchor are green. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · closed-green · P4.1 closed green: authorized EIS probes stopped after three timeouts each; D8 resolved insufficient_evidence, so bounded revision/due revalidation was implemented and verified end-to-end.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · in-progress · P4.2 started: independent frontier/xhigh reviewer; solo admission, immutable five-carrier context and split evidence anchor are green; inherited product diff hash-bound. |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · local-repair · P4.2 RED/nonterminal: A1/NEW-1 stop_reason regression reproduced; product repair returns to P1.4, then a fresh independent P4.2 replay is required. |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · decision-pending · Priority fix declared: P1.4 owns the bounded cursor/stats persistence repair; resume P4.2 only after exact focused, broad and full reruns are green. |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · decision-pending · P1 re-entry justified by invalid terminal evidence; plan-design must scope the P1.4 priority repair and preserve resume P4.2. |
| 2026-09-11 | gpt-5.6-sol/medium | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:61 |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · in-progress · P4.2 fresh replay started after terminal P1.4 priority repair; route, solo admission, immutable context and split evidence anchor are green; product baseline is hash-bound. |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · decision-pending · P4.2 product acceptance GREEN; terminal close refused because seed/worker/public-acceptance carriers are not self-contained and inherited Pint remains RED. Return to P4 design; no live traffic or product diff. |
| 2026-09-11 | gpt-5.6-sol/current-owner-session | plan-run · in-progress · Qualified P4.2 FreshRun repair incident resolved by exact original carrier: 13 passed / 59 assertions. |
| 2026-09-11 | gpt-5.6-sol/current-owner-session | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:65 |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · in-progress · P4.3 started by exact provider-attested owner invocation. First product carrier records bounded authorization and preflight; traffic stays forbidden until environment/profile/scope are exact and GREEN. Split product/closure anchors admitted. |
| 2026-09-11 | gpt-5.6-sol/xhigh | plan-run · decision-pending · P4.3 preflight NO-GO/nonterminal: no accessible production target, env/profile, immutable deployed image or exact crawler scope. All declared Compose checks failed before exec; traffic/window/writes did not start. Resume same evidence chain after prerequisites. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · in-progress · P4.3 resumed after explicit owner override lowered only the route floor xhigh→high. Production scope and D10 traffic gates remain unchanged; fresh target preflight starts fail-closed. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · decision-pending · Route override high is GREEN. Fresh P4.3 preflight remains NO-GO/nonterminal: no accessible production target or exact D10 bindings; all six Compose checks fail before exec. Traffic/window/writes remain zero. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · decision-pending · Maximum local rehearsal complete: readiness and storage recovery GREEN; worker fault 5/49+3/11; focused 23/110; broad 868/3174; full 1639/7441. Production was explicitly withheld, so 72h remains NO-GO/nonterminal. |
| 2026-09-11 | gpt-5.6-sol/high | plan-run · closed-deviations · P4.3 closed under D12: maximum local rehearsal GREEN; production remains NO-GO and uncertified because owner will not provide access or 72h. Rector 3-file migration remains recorded outside P4.3 scope.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-11 | gpt-5.6-terra/medium | plan-exec · closed-green · note сокращена; полный текст: journal.jsonl:72 |
| 2026-09-11 | gpt-5.6-terra/medium | plan-exec · closed-green · note сокращена; полный текст: journal.jsonl:73 |
| 2026-09-11 | gpt-5.6-terra/medium | plan-exec · closed-green · note сокращена; полный текст: journal.jsonl:74 |

## Correction Routing

| Тип | Origin | Outcome | Target / resume |
|:--|:--|:--|:--|
| routing | P4.2 | contract-conflict → priority-fix | P4.2 |
| priority-fix | P4.2 | priority | P1.4 / P4.2 |
| routing | P4.2 | contract-conflict → reopen-phase | P4.2 |
| re-entry | P4.2 | terminal-evidence-invalid | P1 / P4.2 |

## Owner Gates

- P4.3: known deviation
