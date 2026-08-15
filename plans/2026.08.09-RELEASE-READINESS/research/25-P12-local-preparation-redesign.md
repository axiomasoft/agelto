# P12 redesign — локальный production-preparation package

## Inputs and boundary

- Owner refinements 2026-08-15 и D89/D90 — current-plan acceptance ограничен локально проверяемыми
  scripts/docs/templates/fixtures; real operator deployment/rehearsal становится отдельным планом.
- `findings/P12-local-preparation-rag-2026-08-15.md` — свежие official-source факты и явно
  непроверенные version-specific границы.
- P12.1 state/evidence и product commit `c8145ee5` — исторический локальный host carrier;
  accepted deviation не переписывается.
- P12.2 product commit `ad76797a` — существующий repository carrier, который надо довести до
  нового local-only acceptance без GitHub enrollment/host proof.

RAG:— (repo-grounded: `brief/01-refinements.md`, D89/D90, P12 state/journal and current tree).

## Chosen topology

| Item | Local deliverable | Local acceptance | Future rehearsal owns |
|:--|:--|:--|:--|
| P12.2 | repository identity/profile/runbook + redacted template | fake Git/SSH/profile fixtures, syntax, secret scan | key enrollment, remote auth, exact clone |
| P12.3 | ingress/TLS/systemd/backup/watchdog templates and safe helpers | NGINX/systemd/profile/backup hostile fixtures | DNS, certificate issuance, timers, off-host copies, alert delivery |
| P12.4 | first-deploy staged runner and runbook | fake-tool order/failure/resume/idempotency contract | registry pull, migrations, admin, HTTPS/Doctor/browser proof |
| P12.5 | release/rotation/rollback/restore runners and decision trees | fake-tool lifecycle, lock/redaction/destructive guards | live queue drain, rotation, rollback and isolated restore |
| P12.6 | source-derived command matrix and troubleshooting | inventory ↔ Make/scripts/docs equality and safe local command classes | external/mutating command receipts |
| P12.7 | preparation report, evidence templates and follow-up rehearsal brief | aggregate local gate + citations/Task/docs + binary `PREPARATION_READY` | D45 `READY|NOT_READY` and every runtime proof |

P12.2–P12.7 образуют один adjacent batch `B12-PREP`: одна RAG base, один operator-doc surface,
общие Bash/profile conventions и последовательный producer→consumer contract. Все items имеют
`implementation/high`, `Exec=manual`, `Review=full`; owner/external gates внутри batch отсутствуют.
Отдельный workflow не заявлен: один root последовательно закрывает item lifecycle и commits.

## Cross-item implementation contract

1. Каждый production-mutating helper имеет read-only `check`/fixture path; P12 execution запускает
   только local contract carrier и никогда не вызывает apply против remote/runtime state.
2. Scripts координируют уже существующие Make/Compose/application carriers и не копируют их логику.
   Public command inventory извлекается из source и fail-closed на doc/source drift.
3. Credentials принимаются только file paths; fixtures используют sentinel values и проверяют
   absence в argv/stdout/stderr/artifacts. Никаких `eval`, `curl | sh`, `|| true`, floating tags,
   `StrictHostKeyChecking=no`, destructive restore по умолчанию или secret-bearing evidence.
4. Evidence-файлы P12 — схемы/шаблоны. Они не содержат вымышленных timestamps, remote SHAs,
   certificate IDs, alert receipts, RPO/RTO или runtime results.
5. P12.7 выдаёт только `PREPARATION_READY|PREPARATION_NOT_READY`. D45 production verdict
   переносится в follow-up brief вместе с literal runtime gates и required authority.
6. Если local contract выявляет defect в существующем P1–P11 carrier, repair допустим только в
   declared Files текущего item. Исполнитель сначала исследует и repair-forward закрывает максимум
   безопасно доступного scope; отсутствие внешнего состояния не допускает block/escalation. Для
   настоящего cross-phase/public/frozen semantic conflict фиксируется additive D# boundary, после
   чего продолжается вся не конфликтующая локальная часть batch.
7. D90 запрещает парковать item из-за объёма, отсутствия host/access/runtime proof или потребности
   в дополнительном исследовании. Local RED остаётся repair-forward до GREEN; future rehearsal
   boundary не считается Pending Work/deviation текущего P12.

## Evidence ownership after D89

| Carrier | Current P12 meaning | Prohibited claim |
|:--|:--|:--|
| `artifacts/P12-repository-evidence.md` | redacted repository-proof template | GitHub enrollment/auth success |
| `artifacts/P12-backup-evidence.md` | ingress/recovery/timer/alert evidence template | issued TLS, scheduled backup, delivered alert, restored data |
| `artifacts/P12-first-deploy-evidence.md` | staged first-deploy evidence template | pulled digest, migrated DB, live app/browser result |
| `artifacts/P12-release-recovery-evidence.md` | release/rotation/rollback/restore evidence template | queue drain, credential cutover, rollback or restore success |
| `artifacts/P12-command-matrix.md` | locally source-derived command contract | execution receipt for external/mutating row |
| `artifacts/P12-final-readiness-report.md` | compatibility pointer to preparation report/follow-up | production `READY` |

## Pre-mortem

| Failure after 6–12 months | Early signal | Mitigation / owner |
|:--|:--|:--|
| Runbook drifts from source | documented target/script mode missing | P12.6 generated inventory gate; code owner |
| Fixture green is mistaken for deployment proof | evidence template contains success prose/remote timestamps | P12.7 forbidden-claim scan; release owner |
| Secret leaks through diagnostics | sentinel appears in output/artifact | per-item redaction negatives and aggregate scan; security owner |
| Backup guide loses MinIO versions | mirror selected for versioned buckets | explicit capability matrix + future pinned fixture; storage owner |
| Restore script can target production by mistake | missing target identity/allowlist guard | local hostile target fixtures; database owner |
| Follow-up plan omits an external gate | no one-to-one template row in brief | P12.7 trace matrix across D19/D43–D45/D49/D50/D53; owner |

## Requirement trace

- Fresh upstream R&D → finding 2026-08-15 + P12.2/P12.3 citations.
- Literal operator runbooks and safe scripts → P12.2–P12.5.
- Source-derived human/agent command reference → P12.6.
- Cheap deterministic validation and strict citations/Task views → every item-owned contract,
  aggregated in P12.7.
- Real server/DNS/registry/CI/TLS/deploy/backup/restore/rehearsal → explicit follow-up brief from
  P12.7; no Pending Work/deviation in current P12.
- Historical P12.1/P12.2 execution provenance → unchanged journal/state; D89 supplies current
  acceptance and next execution appends recovery rather than rewriting history.

## Проверка (verification status)

- RAG: source material from `findings/P12-local-preparation-rag-2026-08-15.md`; nine primary-source
  queries OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: runtime facts intentionally excluded and assigned to the
  follow-up operator rehearsal brief.
- Обходки/[POSSIBLE-DEFECT]: нет; local fixture verdict is named separately from production READY.
