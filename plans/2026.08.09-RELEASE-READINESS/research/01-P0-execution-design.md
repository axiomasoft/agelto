# P0 execution design

## Sources

- `brief/00-brief.md` — owner requirements.
- `findings/bootstrap-recon-2026-08-09.md` — initial Agelto/reference recon.
- `findings/P0-reference-input-map-2026-08-09.md` — absolute reference paths and dirty-tree boundary.
- `research/00-user-intent.md` — brief-to-phase trace.
- `.ai/guidelines/architecture.md`, `.ai/guidelines/glossary.md`, `AGENTS.md` — project invariants.

Внешний RAG в этом authoring pass не выполнялся; внешние вердикты производят P0.3–P0.5.

## Execution topology

1. P0.1 снимает один Agelto baseline по восьми surfaces. RAG:— (repo-grounded: owner brief + bootstrap recon).
2. P0.2 применяет ту же матрицу к трём references и пинит фактические dirty bytes. RAG:— (repo-grounded: `findings/P0-reference-input-map-2026-08-09.md`).
3. P0.3→P0.4→P0.5 идут последовательно: infrastructure verdicts задают runtime/deploy boundary Laravel-трека, а он — stack/security boundary domain-трека. Это убирает независимый fan-out после RED workflow gate. RAG:— (repo-grounded: `findings/P0-workflow-static-review-2026-08-09.md`, D15).
4. P0.6 единолично владеет cross-track synthesis/freeze и bounded self-audit; после phase close отдельный fresh-context `task:plan-audit P0` проверяет provenance, coverage и consumer consistency. RAG:— (repo-grounded: D4/D5/D15 and plan-protocol Tier policy).

## Common findings schema for P0.3–P0.5

Каждый findings-файл имеет ровно шесть top-level sections:

1. `Scope and queries` — stack/version/timeframe, initial deep query, follow-ups.
2. `Sources` — URL, title/owner, date checked, primary/secondary, applicable version/scope.
3. `Verified findings` — claim, `RAG:✅`, source, failure scenario, affected plan items.
4. `Contradictions and rejected claims` — source disagreement and `REFUTED|UNVERIFIED` verdict.
5. `Recommendations` — evidence-backed input for P0.6, not an accepted D-record.
6. `Open questions` — unresolved fact or owner-policy fork; no silent assumption.

## External fact classes and source policy

| Track | Fact classes | Primary-source targets | Consumers |
|:--|:--|:--|:--|
| P0.3 | Compose topology, BuildKit/cache, Make contracts, secrets, Ubuntu/Docker install, Vite | official Docker/GNU/Ubuntu/Vite documentation and repositories | P1, P2, P11, P12 |
| P0.4 | Laravel/Filament/Livewire lifecycle, Doctor, private docs security, Pest 5 | official documentation, release notes, package repositories/metadata | P2, P3, P4, P9, P11, P12 |
| P0.5 | proxy/settings, MIME/formats, safe archives, procurement taxonomy, registries | official package docs, standards/registries, authoritative procurement sources | P5, P6, P7, P8, P10, P12 |

Правило: primary source не найден или applicable version не доказана → claim остаётся `[UNVERIFIED]`; secondary source может навести на поиск, но не замораживает contract.

## P0.6 synthesis schema

`research/02-P0-target-contracts.md` несёт матрицу:

`owner requirement → local/reference evidence → external verdict → alternatives + cost → D-record/proposed gate → contract clause → consumer items → deterministic acceptance`.

`root/contracts/release-readiness-v1.md` содержит шесть секций:

- Development and production topology;
- Bootstrap, secrets and deploy;
- Application, assets and private docs;
- Proxy, documents and extension seams;
- Release acceptance;
- Consumer and route matrix.

Контракт не копирует findings: каждая clause ссылается на evidence и accepted D-record. Proposed decision не считается frozen.

## Audit and stop rules

- Self-audit dimensions: provenance/RAG, brief coverage, contradictions/alternatives, contract→consumer completeness, validation/routing feasibility.
- Major/Blocker → fix in frontier main loop → repeated self-audit; maximum two refine rounds.
- Два rounds исчерпаны и GREEN нет → P0.6 не закрывается, escalation в main loop/owner.
- GREEN self-audit не заменяет `task:plan-audit P0` после закрытия фазы.
