# Application Architecture

## Resident rules

- `CLAUDE.md` is the generated Claude Code hub; `AGENTS.md` is the compact Codex entrypoint. Shared rules live in `.ai/guidelines/**`; do not recreate retired `GEMINI.md`.
- Keep this hub short. Read `docs/03_Dev/**` or `docs/02_Workflow/**` only for the active task. Architectural changes update their source docs and this guideline.

## Domain boundaries

- Flat `app/**`, no modules: `Procurement` (`prc_*`: procurements, documents, estimates, AI, crawling), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`: provider-agnostic transport).
- Procurement AI pipeline: `Services/Procurement/{Prepare,Screening,Extraction}`, `Jobs/Procurement/*Job`, `Models/Procurement/Ai/*`; LLM transport stays in `Services/Llm/**` with `Models/Llm/**`, repositories and factories. Use its local skills for extraction, LLM transport and prompts.
- Crawling contracts: typed profile filters are resolved through `crawler.filters`; TLS trust exists only in `SourceTlsTrust` / `crawler.tls.bundles`. See `docs/03_Dev/crawling-pipeline.md`.
- Controllers handle HTTP only; Actions/Services own short mutations and transactions; repositories access persistence. Long crawling/parsing/AI work runs as Horizon jobs, never in HTTP.
- A file in `app/<Layer>/<Domain>/` has one subdomain directory; only domain-core and cross-subdomain files may be at its root. `tests/Unit/Architecture/DomainLayoutTest.php` enforces this.
- Jobs must be idempotent. AI jobs use lease + status guard, prefix-stable system prompt and checkpoint-requeue without double dispatch.

## Runtime

- Browser automation runs only in the `horizon` worker (`shm_size`, `CHROME_BIN`).
- Development keeps source bind-mounted and Vite runs with pinned `pnpm`; production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host. The deploy class is explicit: `code`, `php-deps`, `frontend`, `runtime`, or `image-promotion`.

For pipeline stages and schema detail: `docs/03_Dev/architecture.md`, `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.
