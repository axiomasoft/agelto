# INDEX — 2026.08.09-RELEASE-READINESS

## Ядро

| Файл | Роль |
|:--|:--|
| plan.md | стабильное ядро Layout v2: identity, context, rules, draft routing и decision pointer |
| status.md | generated owner view |
| handoff.md | rolling next-step pointer |
| journal.jsonl | append-only история plan-work |
| decisions/ | immutable решения и generated views |
| phases/Pn/Pn.md | contract-first phase context |
| phases/Pn/Pn.m.md | handwritten item specifications |
| phases/Pn/Pn.m.state.md | generated item state |
| root/contracts/read-budget-v1.md | read-admission threshold |

## Слои знаний

| Файл | Потребители |
|:--|:--|
| brief/00-brief.md | все фазы |
| brief/01-refinements.md | D3 · authoring lifecycle |
| findings/bootstrap-recon-2026-08-09.md | P0 · P1–P4 bootstrap context |
| research/00-user-intent.md | DoR всех фаз · финальный coverage sweep P12 |

## Фазы

| Фаза | Назначение |
|:--|:--|
| P0 | repo/reference recon + RAG + target contracts |
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
