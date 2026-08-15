# P3 recon — Livewire/Filament assets, readiness и browser acceptance

Дата: 2026-08-09.

## Repo snapshot

1. `composer show --locked` фиксирует Laravel 13.24.0, Filament 5.7.6, Livewire 4.3.5 и
   Laravel Boost 2.5.3. `composer.json` запускает `filament:upgrade` в `post-autoload-dump`,
   а общий `vendor:publish --tag=laravel-assets --force` — только в `post-update-cmd`.
   RAG:— (repo-grounded: `composer.json`, `composer.lock`).
2. Livewire 4.3.5 строит prefix `/livewire-<8 hex>` из `APP_KEY`, регистрирует core script,
   source maps и component JS/CSS как Laravel routes. Published `public/vendor/livewire/manifest.json`
   является отдельным optional path: без него core script обслуживает Laravel route.
   RAG:— (repo-grounded: locked `vendor/livewire/livewire/src/Mechanisms/{HandleRequests/EndpointResolver.php,FrontendAssets/FrontendAssets.php}`;
   package source SHA `7ef4b2a876c71744e86463079dd506b26eeab624`).
3. `docker/nginx/default.conf` отправляет обычные пути через `try_files ... /index.php`, но более
   специфичный regex для `.css|.js|...` не имеет `try_files`. Поэтому URL вида
   `/livewire-9c644ad8/livewire.js?id=...` совпадает со static location раньше Laravel front
   controller. Это ведущая гипотеза текущего 404; runtime-proof остаётся deliverable P3.1.
   RAG:— (repo-grounded: `docker/nginx/default.conf`, owner URL in `brief/00-brief.md`).
4. Filament `filament:upgrade` вызывает `filament:assets`, затем очищает config/route/view caches;
   install-команда добавляет этот hook в `post-autoload-dump`. Значит lifecycle Filament assets
   уже имеет upstream carrier, но production build/deploy и narrow repair должны доказать, что
   scripts не пропущены и output попал в immutable application image.
   RAG:— (repo-grounded: locked `vendor/filament/support/src/Commands/{UpgradeCommand,AssetsCommand,InstallCommand}.php`;
   package source SHA `7e75d8da9b907ead7d618ce8237228316d08ae43`).
5. `/up` — единственный application HTTP health route; Compose app healthcheck curl'ит его,
   Horizon и scheduler имеют отдельные process/status checks. App-specific DB/cache/storage/assets
   readiness отсутствует. Host `php artisan route:list` сейчас падает до вывода routes, когда имя
   `postgres` недоступно: `routes/console.php` выполняет `Schema::hasTable()` при console bootstrap.
   P3 runtime checks обязаны идти в dependency-ready service topology; host failure нельзя выдавать
   за отсутствие Livewire route. RAG:— (repo-grounded: `bootstrap/app.php`, `routes/console.php`,
   `docker-compose*.yml`, command evidence of this authoring run).
6. В проекте нет `@playwright/test`, `playwright.config.*` или automated E2E specs. Скрипт
   `scripts/playwright-mcp.sh` — ручной MCP launcher для разведки и не является acceptance runner.
   Текущие Compose carriers содержат фиксированные `container_name`, что конфликтует с безопасным
   параллельным clean-clone/candidate-tree запуском. RAG:— (repo-grounded: `package.json`,
   `scripts/playwright-mcp.sh`, `docker-compose*.yml`).

## Official Playwright verification

- npm registry `@playwright/test/latest`, checked 2026-08-09: stable `1.62.1`, Node `>=20`,
  Apache-2.0. Source: https://registry.npmjs.org/@playwright/test/latest. Confidence: high.
- Official configuration docs, checked 2026-08-09: `baseURL`, Chromium project, CI-only retries,
  `forbidOnly` and traces are first-party config surfaces. Source:
  https://playwright.dev/docs/test-configuration. Confidence: high.
- Official auth docs, checked 2026-08-09: setup-project + `storageState` is the reusable auth
  carrier; state may contain impersonation credentials and must not enter Git. Source:
  https://playwright.dev/docs/auth. Confidence: high.
- Official Docker docs, checked 2026-08-09: when a Playwright image is used, package and image
  versions must match and the image should be pinned. Source: https://playwright.dev/docs/docker.
  Confidence: high. P3 chooses local pinned package/browser install, so it does not add a third
  long-running browser service or an unpinned image.

## Verdicts for design

1. P3.1 must prove the Nginx-route hypothesis before P3.2 mutates asset delivery.
2. Livewire dynamic hashed routes remain supported; missing static assets may fall through to
   Laravel but must stay real 404, never HTML-with-200. Filament publication gets one explicit
   check/sync/repair surface reused by build and operator commands.
3. `/up` remains lightweight liveness. Active dependency/assets/worker readiness belongs to one
   reusable service consumed by an Artisan command and Doctor diagnostics, avoiding an HTTP probe
   that performs heavy I/O on every request.
4. Browser acceptance uses pinned native Playwright Test, one Chromium project and ignored auth
   state. Docker lifecycle remains in an outer clean-install runner so teardown and volume scope
   are deterministic.
5. Clean-install isolation requires project-scoped Compose names and no fixed `container_name`;
   this is a P1↔P3 coupling correction, not a new Compose carrier.

## Verification status

- RAG: official Web search/open ×4 + npm registry ×1, OK; repo/source recon, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact Livewire 404 runtime chain and P0.4 official ecosystem
  verdict are intentionally gated by terminal P0.4 and P3.1 evidence.
- Обходки/[POSSIBLE-DEFECT]: none; static diagnosis is explicitly a hypothesis until P3.1.
