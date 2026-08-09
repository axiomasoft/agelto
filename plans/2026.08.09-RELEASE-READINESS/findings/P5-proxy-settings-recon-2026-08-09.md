# P5 proxy/settings recon — 2026-08-09

## Scope and method

Проверены текущие proxy call sites Agelto, profile/binding/health carriers, Filament surfaces,
locked framework versions и официальный контракт `spatie/laravel-settings`. Внешний поиск
ограничен первичными источниками Spatie/GitHub; выводы о коде основаны только на текущем дереве.

## Repo evidence

- Locked stack: Laravel 13.24.0, Filament 5.7.6, Livewire 4.3.5, Pest 5.0.4; пакет
  `spatie/laravel-settings` отсутствует. RAG:— (repo-grounded: `composer.lock`).
- `Crawler.use_proxy=false` сегодня является намеренным direct-режимом, а `true` требует
  непустой `prc_crawler_proxy`; фабрика по умолчанию создаёт direct-профиль. RAG:—
  (repo-grounded: `app/Models/Procurement/Crawler/Crawler.php`,
  `tests/Feature/Crawler/CrawlerProxySettingsTest.php`).
- Listing сам создаёт `ProxyLease::direct()` при `use_proxy=false`; detail/document делают то же
  при пустом объединении bindings; `Okpd2NodeSynchronizer` вызывает pool resolver без profile.
  Поэтому default и единая typed precedence сейчас отсутствуют. RAG:— (repo-grounded:
  `app/Services/Procurement/Eis/CsvSourceMethod.php`,
  `app/Services/Procurement/DocumentDownloader.php`,
  `app/Services/Procurement/Eis/Okpd2NodeSynchronizer.php`).
- `ProxyResolver` — единственный pool selector и владелец score/breaker/outcome; global health
  живёт в `prc_proxies`, per-target — в `prc_proxy_targets`. Direct outcome является no-op.
  RAG:— (repo-grounded: `app/Services/Procurement/Proxy/ProxyResolver.php`,
  `app/Repositories/Procurement/Proxy/ProxyHealthStoreRepository.php`).
- Постоянный fetch evidence хранит `proxy_id`, но не различает profile/default; direct виден
  только как `proxy_id=null` и отдельные сообщения логов. RAG:— (repo-grounded:
  `app/Models/Procurement/Crawler/Fetch.php`, `app/Services/Procurement/FetchLogger.php`).
- `PLAYWRIGHT_MCP_PROXY_SERVER` читается только tooling launcher `scripts/playwright-mcp.sh`;
  application runtime/config его не читает. RAG:— (repo-grounded: exact-name repository search).
- Отдельного application settings carrier или Filament settings page нет. Existing proxy secrets
  принадлежат `Proxy` и уже маскируются при edit; `ProxyPermission::Update` входит в OperatorRole.
  RAG:— (repo-grounded: `app/Filament/Resources/Procurement/Crawler/Proxies/`,
  `app/Guards/Admin/Permissions/ProxyPermission.php`, `app/Guards/Admin/Roles/OperatorRole.php`).

## Official Spatie evidence

- Current release на 2026-08-09 — `spatie/laravel-settings` 3.9.0 (release 2026-05-26), repository
  active. Source: https://github.com/spatie/laravel-settings/releases — checked 2026-08-09,
  confidence high. RAG:✅ 2026-08-09 official GitHub releases.
- Main package constraint: PHP `^8.2`, `illuminate/database ^11|^12|^13`; Agelto PHP `^8.4` и
  Laravel 13 входят в поддержанный runtime range. Source:
  https://github.com/spatie/laravel-settings/blob/main/composer.json — checked 2026-08-09,
  confidence high. RAG:✅ 2026-08-09 official package composer manifest.
- Пакет предоставляет typed Settings classes через container injection, database/Redis
  repositories, settings migrations, nullable defaults, cache refresh after edits, explicit
  `refresh()`, events и encrypted properties. Source:
  https://github.com/spatie/laravel-settings#readme — checked 2026-08-09, confidence high.
  RAG:✅ 2026-08-09 official package README.

## Verdict

Spatie Settings уместен как typed, расширяемый и Laravel-13-compatible carrier. Для P5 он должен
хранить только nullable `default_proxy_id`, ссылающийся на существующий `prc_proxies`: raw proxy
URL/username/password в settings запрещены. Dedicated database repository использует
`prc_settings`, чтобы не размывать четыре domain prefixes. Long-lived Horizon читает значение
через refresh-aware read service; production cache допустим только с тестом cross-process refresh.
RAG:— (repo-grounded synthesis: D8, architecture prefixes and official evidence above).

## Risks to preserve in execution

1. Existing `use_proxy=false` is explicit direct intent; silently migrating it to inherit-default
   changes egress. Preserve it as typed `direct`.
2. Configured profile/default proxy becoming unhealthy is not the same as «не настроен»: falling
   through to direct would bypass policy. Resolver must fail visibly.
3. A single default node must carry `crawl_listing`, `crawl_detail`, and `document_download` scopes;
   Filament validates this before save, resolver repeats fail-closed validation.
4. P0.5/P0.6 have not run. Their official RAG/frozen contract remain the execution-time authority;
   P5.1 reconciles this authoring verdict before package/schema mutation.

## Verification status

- RAG: official Web/GitHub ×3 fact classes OK; repo inspection OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: terminal P0.5/P0.6 products — deliberately gated by P5.1.
- Обходки/[POSSIBLE-DEFECT]: none.
