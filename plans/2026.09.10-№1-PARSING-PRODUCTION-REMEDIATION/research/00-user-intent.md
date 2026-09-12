# User intent trace

| Мысль владельца | Носитель |
|:--|:--|
| Создать remediation документ | `artifacts/PARSING_PRODUCTION_REMEDIATION.md` |
| Создать новый Layout v2 plan | `plan.md`, `status.md`, `phases/**`, `handoff.md` |
| Пять зависимых фаз | P0–P4 и contracts в phase indexes |
| A1–A9, B1, NEW-1, NEW-2 | P0.1, P1.1–P1.4, P2.1–P2.2, P3.1–P3.5, P4.1 |
| B2 explicit + live gate | D8 (supersedes D4), P2.3, P4.1; независимая приёмка P4.2 |
| B3 nonblocking observability | D5, P4.4; не prerequisite P4.3 |
| Exact Files/tests/commands/closure | все `phases/Pn/Pn.m.md` |
| Design-only | D1; Git scope ограничен docs/plans/ACTIVE |
| Preserve dirty work | Execution Rules + per-item Code Guidance |
| Mandatory Task checks | findings pre-check + postflight и handoff receipt |
| Repair RED design audit #1 | D11; explicit `Inputs` P1.1–P4.4; P4.1 A1/B2 live ownership; P4.2 offline evidence/Files contract; повторный whole-plan design audit |
