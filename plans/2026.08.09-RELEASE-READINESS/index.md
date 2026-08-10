# INDEX — 2026.08.09-RELEASE-READINESS

## Ядро

| Файл | Роль |
|:--|:--|
| plan.md | стабильное ядро Layout v2: identity, context, rules, draft routing и decision pointer |
| status.md | generated owner view |
| handoff.md | rolling next-step pointer |
| roadmap.md | execution order, solo launches and owner gates after full phase detailing |
| journal.jsonl | append-only история plan-work |
| decisions/ | immutable решения и generated views |
| phases/Pn/Pn.md | contract-first phase context |
| phases/Pn/Pn.m.md | handwritten item specifications |
| phases/Pn/Pn.m.state.md | generated item state |
| plans/verify-citations.py | P0.0 project-level citation gate; pending execution |
| plans/verify-citations-known-exceptions.json | P0.0 data-only exact exceptions; pending execution |
| root/contracts/read-budget-v1.md | read-admission threshold |
| root/contracts/release-readiness-v1.md | P0.6 frozen cross-phase contract; `PENDING` до исполнения P0.6 |

## Слои знаний

| Файл | Потребители |
|:--|:--|
| brief/00-brief.md | все фазы |
| brief/01-refinements.md | D12 · current authoring lifecycle; D3 superseded |
| findings/bootstrap-recon-2026-08-09.md | P0 · P1–P4 bootstrap context |
| findings/P0-reference-input-map-2026-08-09.md | P0.2 · D14 external-tree provenance |
| findings/P0-workflow-static-review-2026-08-09.md | D15 · blocked workflow evidence |
| findings/P0-contract-self-audit.md | P0.6 bounded contract/consumer audit; `PENDING` до исполнения |
| findings/P1-docker-make-vite-recon-2026-08-09.md | P1.1–P1.5 · D16–D18 repo/RAG basis |
| findings/P1.6-downstream-*-cb49b47cd3fe.md | P1.6 first downstream run · raw GREEN/RED/unknown evidence for D65; append-only |
| findings/P2-vendor-secrets-doctor-recon-2026-08-09.md | P2.1–P2.6 · D19–D21 repo/RAG basis |
| findings/P3-livewire-readiness-recon-2026-08-09.md | P3.1–P3.5 · D22–D25 locked-source/repo/RAG basis |
| findings/P3.1-livewire-runtime-evidence.md | P3.1 output carrier · P3.2 hard gate; `PENDING` до исполнения |
| findings/P4-private-documentation-recon-2026-08-09.md | P4.1–P4.5 · D26/D27 repo/reference/security basis |
| findings/P5-proxy-settings-recon-2026-08-09.md | P5.1–P5.5 · D28/D29 settings/egress basis |
| findings/P6-format-archive-recon-2026-08-09.md | P6.1–P6.6 · D30/D31 repo/security/runtime basis |
| findings/P7-screening-contract-recon-2026-08-09.md | P7.1–P7.6 · D32–D34 repo/schema/prompt/UI basis |
| findings/P8-platform-status-extension-recon-2026-08-09.md | P8.1–P8.5 · D35/D36 adapter identity/status applicability basis |
| findings/P9-filament-v5-rag-2026-08-09.md | P9.1–P9.6 · official Filament 5 navigation/enum/schema/table contracts |
| findings/P9-filament-surface-recon-2026-08-09.md | P9.1–P9.6 · D37/D38 resource/page/schema/table/auth inventory |
| findings/P10-quality-security-recon-2026-08-09.md | P10.1–P10.6 · authoring tooling/audit surface and D39 basis |
| findings/P10-static-analysis-audit.md | P10.1 execution carrier · P10.2/P10.5/P10.6 hard input; pending execution |
| findings/P10-security-audit.md | P10.2 execution carrier · P10.5 hard input; pending execution |
| findings/P10-architecture-debt-audit.md | P10.3 execution carrier · P10.5 hard input; pending execution |
| findings/P10-production-readiness-audit.md | P10.4 execution carrier · P10.5 hard input; pending execution |
| artifacts/P10-remediation-matrix.md | P10.5 disposition/fix carrier · P10.6 hard input; pending execution |
| findings/P11-pest-ci-recon-2026-08-09.md | P11.1–P11.6 · repo/official Pest/Laravel/PAO/GitHub basis for D40–D42 |
| findings/P12-ubuntu-deployment-rag-2026-08-09.md | P12.1–P12.7 · official Ubuntu/Docker/GitHub/Nginx/Certbot/PostgreSQL/MinIO basis for D43–D45 |
| artifacts/P11-test-baseline.md | P11.1 benchmark carrier · P11.2–P11.6 hard input; pending execution |
| artifacts/P11-risk-test-matrix.md | P11.1 inventory/P11.4 closure carrier · P11.5/P11.6 hard input; pending execution |
| artifacts/P11-final-quality-report.md | P11.6 final coverage/performance/flake/live-CI evidence · P12 hard input; pending execution |
| artifacts/P12-host-evidence.md | P12.1 host baseline/security evidence · P12.2/P12.7 hard input; pending execution |
| artifacts/P12-repository-evidence.md | P12.2 exact Git/private-package evidence · P12.3/P12.7 hard input; pending execution |
| artifacts/P12-backup-evidence.md | P12.3 pre-deploy owner policy plus config/fixture evidence · P12.5 post-deploy real recurring backup/retention/watchdog/alert/application-checkable restore evidence · P12.4/P12.7 hard input; pending execution |
| artifacts/P12-first-deploy-evidence.md | P12.4 first production deploy evidence · P12.5/P12.7 hard input; pending execution |
| artifacts/P12-release-recovery-evidence.md | P12.5 release/D20 rotation apply+rollback/previous-digest rollback/full-restore evidence · P12.6/P12.7 hard input; pending execution |
| artifacts/P12-command-matrix.md | P12.6 source-derived command/troubleshooting ledger · P12.7 hard input; pending execution |
| artifacts/P12-final-readiness-report.md | P12.7 final `READY|NOT_READY` report; pending execution |
| research/00-user-intent.md | DoR всех фаз · финальный coverage sweep P12 |
| research/01-P0-execution-design.md | P0.1–P0.6 · research schema · sequential/audit topology; P0.0 prerequisite governed by D48 |
| research/02-P0-target-contracts.md | P0.6 alternatives/trace/route matrix; `PENDING` до исполнения |
| research/03-P1-execution-design.md | P1.1–P1.5 · Compose/image/Vite contracts and gates |
| research/17-P1.6-downstream-retry-design.md | P1.6 retry · D65 item-owned future-consumer carrier normalization |
| research/04-P2-execution-design.md | P2.1–P2.6 · vendor/secrets/bootstrap/admin/Doctor contracts |
| research/05-P3-execution-design.md | P3.1–P3.5 · asset/readiness/browser/isolation contracts |
| research/06-P4-execution-design.md | P4.1–P4.5 · private docs threat/build/serve contracts |
| research/07-P5-execution-design.md | P5.1–P5.5 · settings/proxy precedence and health contracts |
| research/08-P6-execution-design.md | P6.1–P6.6 · typed format, bounded archive and child lifecycle contracts |
| research/09-P7-execution-design.md | P7.1–P7.6 · semantic screening envelope, disposition and operator contract |
| research/10-P8-execution-design.md | P8.1–P8.5 · platform definition, applicability schema and extension test contract |
| research/11-P9-execution-design.md | P9.1–P9.6 · Russian IA, label/schema/table and browser acceptance contract |
| research/12-P10-execution-design.md | P10.1–P10.6 · baseline/audit/disposition/remediation/gate contract |
| research/13-P11-execution-design.md | P11.1–P11.6 · measured Pest/isolation/risk/CI/final-gate contract |
| research/14-P12-execution-design.md | P12.1–P12.7 · Ubuntu host/source/ingress/deploy/recovery/rehearsal contract |

## Фазы

| Фаза | Назначение |
|:--|:--|
| P0 | citation prerequisite + repo/reference recon + RAG + target contracts |
| P1 | Makefile, Docker dev/prod и Vite-ready topology |
| P2 | local vendor, secrets, preflight, bootstrap, Doctor |
| P3 | fresh install, Livewire/Filament assets и readiness |
| P4 | закрытая production-документация |
| P5 | default proxy settings и трёхуровневый resolve |
| P6 | file registry, archives и child processing |
| P7 | document type, summary, estimates и tags |
| P8 | platform/status mapping extensibility |
| P9 | Filament information architecture и UX |
| P10 | PHP/architecture/security/production audit |
| P11 | Pest 5, test isolation/performance и CI |
| P12 | Ubuntu deployment docs и финальный rehearsal |
