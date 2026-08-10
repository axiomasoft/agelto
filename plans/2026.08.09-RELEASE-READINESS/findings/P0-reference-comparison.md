# P0.2 — Сравнительная матрица reference repositories

RAG:— (repo-grounded: read-only local snapshots below). This is a comparison of observed working-tree bytes, not an external best-practice verdict. No Compose, deploy, migration, seed, or secret-value read was performed.

## Snapshot provenance

| Reference | Absolute path | HEAD | Dirty entries | `git status --porcelain=v1` SHA-256 | Read result |
|:--|:--|:--|--:|:--|:--|
| Sova | `/home/vostrikov/sibintek/projects/sova` | `57b50ad786111c07a9fa2740aee84b8fcc53b9f8` | 340 | `ec51bd49c9b94382579f3c2c9c82bed4a21388530e4cc74234f1753d87865efb` | Stable before/after; all 6 selected files sealed. |
| Vintera/CMS | `/home/vostrikov/projects/vintera/cms` | `94b6e3f49a75744f8b775bc49d7c27fd61e30420` | 18 | `39f761c44d18e3f00e957405a2bb60e83404a2cfd341e0769f68a6d9dba246c6` | Stable before/after; all 10 selected files sealed. |
| Dterema | `/home/vostrikov/projects/dterema/dterema` | `205ea3415f19960a9ed4e7ae29e00b103381c464` | 6 | `f83f0eb436a340d39e824084fc80c062798ba0b7a31f16ed995d4983b0af68ad` | Stable before/after; `docker/Makefile` and `docs/operations/deployment.md` are dirty-only evidence. |

`P0-reference-snapshot.sha256` seals every actually read file in sha256sum-compatible form. The status digests and file hashes were unchanged after reading; a missing or changing path did not occur. RAG:— (repo-grounded: local `git` and `sha256sum` reads on 2026-08-10).

## Comparison matrix

| Surface | Evidence across references | Strength / named liability | Applicability to Agelto | Consumer phase |
|:--|:--|:--|:--|:--|
| Make taxonomy | All roots delegate to `docker/Makefile`; Sova exposes dev/prod aliases, Vintera selects `ENV`, Dterema derives forwarding targets. | One root entrypoint is discoverable; derived/alias-heavy targets can obscure the exact production path. | Keep Agelto root delegation; make target contracts explicit and testable rather than inferable from aliases. | P1, P11, P12 |
| Dev/prod Compose | Sova has separate dev/prod files; Vintera switches both file and env-file set; Dterema has dev bind mounts and prod image-plus-named-volumes. | Topology separation is visible; Sova prod code bind mount weakens image immutability. | Preserve separate Agelto Compose topology; decide image/promotion boundary with P0.3 before copying a reference layout. | P1, P12 |
| Image/build/cache boundary | Sova runtime image omits Composer install; Vintera adds a Node-only builder; Dterema installs Composer dependencies during image build. | Dedicated frontend builder constrains Node work; baking dependency installation requires reproducible source/package policy. | Agelto private path repositories and document tools need a dedicated image reproducibility decision. | P1.3, P2.1, P6, P12 |
| Env and secret guards | Vintera and Dterema require a distinct prod env file; Sova conditionally layers Docker env. | Fail-fast file-presence guard is observable; none proves secret leakage resistance. | Add a validated preflight around Agelto file-secret carrier and recorded mismatch. | P2.2, P2.3, P12.4 |
| Vite/frontend | Sova runs Vite from the PHP image; Vintera builds through a Node profile and named volumes; Dterema runs an ephemeral Node Vite service. | A separate Node builder isolates frontend work; volume outputs require explicit cleanup/promotion semantics. | Evaluate Vintera build separation for Agelto, whose Docker flow lacks first-class Vite. | P1.5, P3.1, P9 |
| Deploy, doctor, rollback | Sova supplies deploy variants and doctor; Vintera documents scenario deploy, backup and rollback; Dterema separates bootstrap from readiness-gated release. | Scenario names improve operator choice; documented commands are carriers, not fresh-host proof. | Reuse the bootstrap/release distinction only after P0.3/P0.4 evidence; P12 must rehearse it. | P2.4, P12 |
| Admin/assets | Sova shares build/Filament volumes; Vintera has `filament-assets`; Dterema has a production assets target. | Explicit Filament publication avoids conflating it with Vite; volumes can retain stale assets. | Treat Filament assets as a separate acceptance surface from Vite output. | P3.1, P9, P12 |
| Protected docs | Vintera serves a handbook through constrained realpath resolution, but middleware lets non-HTML assets bypass authorization. | Path traversal defence is explicit; unauthenticated assets make it unsuitable as Agelto private-doc proof. | Do not adopt this authorization split; P4 needs one protected-document boundary. | P4, P12 |
| Test/DB preflight | All three Makefiles expose preflight/test flows and isolated DB creation or checks. | Naming a preflight supports reproducibility; none was run here. | Keep Agelto test isolation guard and add deterministic CI evidence rather than copy a command catalogue. | P11, P12 |
| Proxy | No selected reference file provided a resolver or health pattern. | No comparable evidence. | Do not infer proxy design from Docker networking. | P5, P12 |

## Patterns to adopt

Provisional local patterns only; P0.3–P0.5 must validate them before a frozen contract.

- Keep one root Make entrypoint with a small, explicit production taxonomy; consumer: P1.
- Separate Vite output from runtime lifecycle and verify Filament assets independently; consumers: P1.5, P3.1, P9.
- Require production-specific env-file presence before deployment targets, then validate Agelto file-secret preflight; consumers: P2.2, P2.3, P12.
- Distinguish bootstrap from ordinary readiness-gated release and record backup/rollback receipts; consumers: P2.4, P12.

## Patterns to reject

- Production bind-mounted source as the release image boundary (Sova liability); consumers: P1.3, P12.
- HTML-only authorization with publicly served handbook assets (Vintera liability); consumers: P4, P12.
- Dependency installation during image build without a pinned promotion policy (Dterema liability); consumers: P1.3, P2.1, P12.
- Any proxy conclusion from this comparison; no proxy evidence was read; consumers: P5, P12.

## Open questions

- P0.3 validates Docker/BuildKit, Compose and Vite constraints before adoption becomes a contract.
- P0.4/P0.5 validate Laravel/Filament assets and protected-document security boundaries.
- P12 demonstrates selected bootstrap, rollback and backup on a clean host; this matrix proves no runtime behaviour.
