# P4 recon — private production documentation

Дата проверки: 2026-08-09.

## Scope and queries

Проверены текущие Agelto auth/build carriers, измеренная реализация Vintera/CMS и первичные
источники для VitePress, Laravel file responses/route middleware, HTTP caching и path traversal.
Цель — выбрать generator/storage/serving contract без публикации хотя бы одного artifact в
`public` и без второй неавторизованной asset-ветки.

External fact classes: актуальная стабильная версия и build contract VitePress; Laravel 13 file
response и route middleware; cache semantics для чувствительного authenticated content; защита
file-serving path и MIME sniffing.

Context7 в текущем наборе инструментов отсутствовал; выполнен официальный Web RAG по известным
primary-source страницам. Secondary/forum sources не использовались.

## Repo and reference evidence

1. Agelto не содержит handbook generator, docs build scripts или production docs route. Node/Vite
   carrier пока ограничен application frontend. RAG:— (repo-grounded: `package.json`,
   `pnpm-lock.yaml` отсутствует, `routes/web.php`).
2. Agelto использует Laravel 13.24.0, Filament 5.7.6, Livewire 4.3.5 и AzGuard. Admin panel id —
   `admin`; `User::canAccessPanel()` даёт грубый вход любому пользователю с ролью, а конкретные
   действия режутся permission enum/catalоg. RAG:— (repo-grounded: `composer.lock`,
   `app/Models/User.php`, `app/Guards/Admin/AdminGuardPanelProvider.php`).
3. SuperAdminRole несёт global wildcard; AnalystRole/OperatorRole не должны автоматически получать
   доступ к документации. Новый enum в `app/Guards/Admin/Permissions/*Permission.php` будет
   обнаружен текущим provider без ручного списка. RAG:— (repo-grounded:
   `app/Guards/Admin/Roles/SuperAdminRole.php`, `app/Guards/Admin/AdminGuardPanelProvider.php`).
4. Текущие shared runtime rules всё ещё называют yarn/vendor volumes, тогда как принятые D17/D18
   задают future immutable image и pnpm. P4 не редактирует guideline раньше P1; исполнение P4
   обязано дождаться terminal P1.5 и читать его итог. RAG:— (repo-grounded:
   `.ai/guidelines/architecture.md`, D17, D18).
5. Vintera/CMS working-tree bytes используют VitePress 2.0.0-alpha.17, `base=/handbook/`, build в
   `storage/app/handbook-dist`, общий controller и wildcard route. Middleware пропускает любой
   non-HTML extension без auth, а prod Compose передаёт dist через named volume. Это полезный
   route/controller reference, но обе границы запрещены D7/D17. RAG:— (repo-grounded reference
   snapshot: `HandbookController.php` SHA-256
   `02d648b060c49965a390d664c04e57b81ef14ebbfa96be4938a0b50d43fec094`,
   `EnsureHandbookAccess.php` SHA-256
   `e096b6c6ee7dded025123064c98fba9244c9b3d664c8454fc25985d0ab4e9725`,
   `docker-compose.prod.yml` SHA-256
   `37a896cebd95deba514b738f7be0f857365e2820d659c4202ba76e197280393c`).

## Official sources

### VitePress

- https://vuejs.github.io/vitepress/v1/ — checked 2026-08-09. Official stable channel is 1.6.4;
  the current next docs advertise 2.0.0-alpha.19. Verdict: release-readiness must pin stable 1.6.4,
  not copy Vintera's older alpha. Confidence: high.
- https://vitepress.dev/guide/getting-started — checked 2026-08-09. VitePress is an SSG for
  Markdown; the official nested-project layout is `docs/.vitepress`, and `docs:build` is the
  canonical script. Verdict: it fits the existing `docs/` source tree and future pnpm carrier.
  Confidence: high.
- https://vitepress.dev/reference/site-config — checked 2026-08-09. `base` controls a subpath and
  `outDir` is configurable outside the default source directory. Verdict: build URLs may target
  `/admin/documentation/`, while output remains outside `public`. Confidence: high.
- https://vitepress.dev/guide/deploy — checked 2026-08-09. Production artifacts include hashed
  JS/CSS and official deployment guidance treats them as one static set. Verdict: Agelto may serve
  them through one authenticated handler but must override public-cache advice for sensitive data.
  Confidence: high.

### Laravel

- https://laravel.com/docs/13.x/responses — checked 2026-08-09. `response()->file($absolutePath,
  $headers)` displays a file and accepts explicit headers. Verdict: a single Laravel
  `BinaryFileResponse` carrier can serve HTML and assets without a second Nginx/public route.
  Confidence: high.
- https://laravel.com/docs/13.x/middleware — checked 2026-08-09. `auth` and `can` are built-in
  route middleware; custom middleware may be bound to the whole wildcard route. Verdict: auth and
  permission checks can cover every artifact before the controller reads bytes. Confidence: high.
- https://laravel.com/docs/13.x/authorization — checked 2026-08-09. Gate authorization is the
  framework authorization carrier. Agelto keeps the concrete decision in its existing AzGuard
  enum/catalоg rather than introducing a second RBAC system. Confidence: high.

### HTTP and file-serving security

- https://www.rfc-editor.org/rfc/rfc9111.html — checked 2026-08-09. `no-store` forbids storage by
  private and shared caches; `private` alone still permits a private cache to store the response.
  Verdict: every successful documentation response uses `Cache-Control: private, no-store` and
  never `public`, `s-maxage` or immutable caching. Confidence: high.
- https://owasp.org/www-community/attacks/Path_Traversal — checked 2026-08-09. OWASP recommends
  known-good validation, keeping sensitive files outside web root, normalization, and tests for
  encoded/double-encoded separators. Verdict: build manifest allowlist + safe path grammar +
  `realpath` containment are all mandatory; substring-only `..` filtering is insufficient.
  Confidence: high.
- https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html — checked
  2026-08-09. Explicit MIME and `X-Content-Type-Options: nosniff` prevent unintended type sniffing.
  Verdict: MIME comes from a closed build manifest, unknown extensions are 404, and `nosniff` is
  emitted for every artifact. Confidence: high.

## Threats and required controls

| Threat | Failure mode | Required control / proof |
|:--|:--|:--|
| Public bypass | Nginx/public/named-volume path returns bytes without Laravel | dist outside `public` and writable volumes; image inspection + direct URL tests |
| Coarse panel auth | any user with any admin role can read docs | dedicated `DocumentationPermission::View`; no Analyst/Operator grant |
| Asset bypass | HTML protected, JS/CSS/fonts public | one wildcard route and identical auth/header pipeline for every manifest entry |
| Traversal/symlink | route reaches `.env`, source or arbitrary file | ASCII safe-path grammar, manifest allowlist, no symlinks, `realpath` containment, encoded corpus |
| MIME/XSS | unknown file served as active browser content | closed MIME map, `nosniff`, unknown extension rejected, HTML CSP/referrer/frame controls |
| Shared/private cache leak | authenticated bytes reused after logout or for another user | `private, no-store` on every 200; no ETag/immutable/public branch; browser + HTTP assertions |
| Supply-chain drift | prerelease/latest generator changes output | exact VitePress 1.6.4 + frozen pnpm lock; no `@next`/`@latest` |
| Mutable deployment artifact | docs volume/build differs from image tag | atomic build in image stage; manifest/hash check; no prod-host docs build |
| External exfiltration | generated HTML loads analytics/CDN/remote assets | no service worker/analytics/CDN; CSP blocks remote connect/script/style/img/font; build scan |

## Verdicts for P4

1. Pin exact stable VitePress 1.6.4 on the P1 pnpm carrier; do not add the Vintera mermaid plugin or
   VitePress 2 alpha without a separate evidence-backed decision.
2. Build `/admin/documentation/` into an immutable image directory outside `public`, `storage`
   volumes and source docs; write a deterministic manifest of relative path, MIME and SHA-256.
3. Serve only manifest entries through one Laravel controller/locator. No `X-Accel-Redirect`,
   signed public URLs or direct Nginx alias in the first release.
4. Authorize with `DocumentationPermission::View` scoped to panel `admin`. SuperAdmin wildcard is
   the only initial role path; later roles/direct grants use the same enum without route changes.
5. P4.1 rechecks terminal P0.4/P0.6/P1.5/P3.5. Any contradiction to these verdicts stops for
   plan-design and a superseding D-record; implementation does not improvise.

## Verification status

- RAG: official Web RAG, 10 primary pages + locked/repo/reference source inspection, OK.
- P4.1 reconciliation, 2026-08-11: all four required upstream item-state projections are terminal.
  P0.4 confirms the Laravel-response research carrier; P0.6 confirms its contract/check closure;
  P1.5 records the green P1 release carrier (`0db08b00`, product `7f203014`, closure
  `56aeda8e`); P3.5 records controlled asset recovery and authenticated browser smoke (2/2).
  None changes the stable VitePress version, `/admin/documentation/` base, immutable image boundary,
  one-route authorization model, or response policy fixed by D26/D27.
- Обходки/[POSSIBLE-DEFECT]: none. PHP-per-asset cost is an accepted first-release trade-off, not
  hidden behind a public cache or alternate asset route.
