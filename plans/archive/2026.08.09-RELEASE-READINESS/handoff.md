# HANDOFF — 2026-08-15 — after P12

**Next:** план закрыт

**Done:** P12 закрыта по GREEN audit candidate
`1034072baef9e677a6ba145bb4bb17b0261f6d6f0d1c644d61f12ca7a8610d17`. Все фазы плана
терминальны; агрегатный статус — `🟠 Done with deviations`.

**Remaining:** Внутри этого плана работы нет. План архивирован; отдельный operator rehearsal plan —
последующее owner-selected действие.

**Sources of truth:** `plan.md` · `phases/P12/P12.md` · `status.md` ·
`decisions/D89-p12-local-preparation-boundary.md` ·
`decisions/D90-p12-complete-max-no-external-block.md`.

**Open risks:** Exact Ubuntu 24.04 Certbot installation selector and pinned Community MinIO/`mc`
metadata matrix still require future disposable runtime verification. Strict citation scan has 82
pre-existing findings outside the P12 close diff; they require separate ownership.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: real host/DNS/GitHub/registry/CI/TLS/deploy/backup/alert/rotation/rollback/restore/browser evidence and D45 verdict belong to a separate operator rehearsal plan
- open_questions: —

## Archive migration checklist

- Перенесено в документацию: `root/contracts/release-readiness-v1.md` → `docs/03_Dev/contracts/release-readiness-v1.md`; `root/contracts/read-budget-v1.md` → `docs/03_Dev/contracts/read-budget-admission-v1.md`.
- Осталось в архиве: `artifacts/`, `findings/`, `research/`, решения, phase/item carriers и журнал — как provenance и rollback evidence.
- Живые документационные и contract-test ссылки переведены на `plans/archive/2026.08.09-RELEASE-READINESS`.
