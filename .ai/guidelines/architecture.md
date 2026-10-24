# Application Architecture

## Resident rules

- `CLAUDE.md` is the generated Claude Code hub; `AGENTS.md` is the compact Codex entrypoint. Shared rules live in `.ai/guidelines/**`; do not recreate retired `GEMINI.md`.
- Keep this hub short. Read `docs/dev/**` or `docs/workflow/**` only for the active task. Architectural changes update their source docs and this guideline.

## Domain boundaries

- Flat `app/**`, no modules: `Procurement` (`prc_*`: procurements, documents, estimates, AI, crawling), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`: provider-agnostic transport), `Platforms/<Code>` (platform-specific adapters across layers; existing table names stay unchanged).
- Procurement AI pipeline: `Services/Procurement/{Prepare,Screening,Extraction}`, `Jobs/Procurement/*Job`, `Models/Procurement/Ai/*`; LLM transport stays in `Services/Llm/**` with `Models/Llm/**`, repositories and factories. Use its local skills for extraction, LLM transport and prompts.
- Общие DTO, модели и резолверы не содержат полей и веток конкретной площадки. Внешние идентификаторы организаций передаются как `(platformCode, type, value)`; их разбор и форматы принадлежат адаптеру в `Services/Platforms/<Code>/`. Текущие legacy зависимости ограничены сокращаемым architecture baseline до owning cutovers.
- Crawling contracts: typed profile filters are resolved through `crawler.filters`; TLS trust exists only in `SourceTlsTrust` / `crawler.tls.bundles`. See `docs/dev/crawling-pipeline.md`.
- Controllers handle HTTP only; Actions/Services own short mutations and transactions; repositories access persistence. Long crawling/parsing/AI work runs as Horizon jobs, never in HTTP.
- A file in `app/<Layer>/<Domain>/` has one subdomain directory; only domain-core and cross-subdomain files may be at its root. `tests/Unit/Architecture/DomainLayoutTest.php` enforces this.
- `Platforms/<Code>` uses matching roots in Services/Dto/Enums/Models/Filament, factories and seeders. Service subdirectories: Card, Csv, Listing, Status, Documents, Filters, Validators, Diagnostics, Persistence; one level only. Platform Filament roots contain form/schema components outside resource discovery. `PlatformsDomainLayoutTest` enforces direction, exact integration roles and the shrinking `tests/Unit/Architecture/platforms-dependency-baseline.php`; see `docs/dev/platform-extension.md`.
- Jobs must be idempotent. AI jobs use lease + status guard, prefix-stable system prompt and checkpoint-requeue without double dispatch.

## Runtime

- Browser automation runs only in the `horizon` worker (`shm_size`, `CHROME_BIN`).
- Development keeps source bind-mounted and Vite runs with pinned `pnpm`; production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host. The deploy class is explicit: `code`, `php-deps`, `frontend`, `runtime`, or `image-promotion`.
- Internal VitePress output is built in the frontend stage into `var/private-documentation/site` with its manifest beside it; the application image copies it read-only outside `public` and writable volumes. Documentation source changes require an image artifact, never `prod:deploy:code`.

For pipeline stages and schema detail: `docs/dev/architecture.md`, `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md`.
