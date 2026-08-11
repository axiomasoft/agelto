# P5 proxy/settings recon — 2026-08-09 · reconciled 2026-08-11

## Scope and method

P5.1 повторно проверил текущие proxy call sites Agelto, profile/binding/health carriers, Filament
surfaces, terminal продукты P0.5/P0.6 и официальный контракт `spatie/laravel-settings`. Внешняя
проверка ограничена первичными источниками Spatie/GitHub; выводы о коде основаны на текущем дереве.
P0.5 product восстановлен из item-коммита `f1cb83dd`, P0.6 frozen-contract product — из
`52fb69c6`; upstream Completion Notes не подменялись и остаются append-only.

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

- Current release на 2026-08-11 — `spatie/laravel-settings` 3.9.0 (release 2026-05-26), repository
  active. Source: https://github.com/spatie/laravel-settings/releases — checked 2026-08-11,
  confidence high. RAG:✅ 2026-08-11 official GitHub latest release.
- Tag `3.9.0` требует PHP `^8.2` и `illuminate/database ^11.0|^12.0|^13.0`; Agelto PHP `^8.4`
  и Laravel 13 входят в поддержанный runtime range. Source:
  https://github.com/spatie/laravel-settings/blob/3.9.0/composer.json — checked 2026-08-11,
  confidence high. RAG:✅ 2026-08-11 official tagged composer manifest.
- Пакет предоставляет typed Settings classes через container injection, database/Redis
  repositories, settings migrations, nullable defaults, cache refresh after edits, explicit
  `refresh()` и settings events. Source:
  https://github.com/spatie/laravel-settings#readme — checked 2026-08-11, confidence high.
  Документация гарантирует reload уже загруженного instance через `refresh()`; cross-process
  Horizon freshness остаётся обязанностью P5.2/P5.5 test carrier, а не неявной гарантией пакета.
  RAG:✅ 2026-08-11 official package README + Context7 projection of the same source.

## Terminal P0 reconciliation

- P0.5 state terminal (`🟢 Done`, journal line 65); его committed product
  `findings/P0-rag-domain-security.md` подтверждает D8/EXT-01–EXT-04 и намеренно оставляет
  named package/version и worker freshness P5-consumer'ам. Historical unresolved marker не является
  опровержением D28: это fail-closed вход для текущей проверки.
- P0.6 state terminal (`🟢 Done`, journal line 66); item-коммит `52fb69c6` замораживает
  `root/contracts/release-readiness-v1.md`. EXT-01–EXT-04 требуют ровно одного `ProxyResolver`,
  precedence profile → optional default → direct, отдельного global/per-target health,
  tooling-only Playwright carrier и единственного `SourceTlsTrust`.
- Fresh official check выше закрывает единственную proxy/settings дыру P0.5: `3.9.0` совместим
  с текущим PHP/Laravel и несёт нужные repository/migration/refresh primitives. D28/D29 и frozen
  release contract согласованы; evidence-backed contradiction отсутствует, новая D не создаётся.

## Runtime and tooling call-site matrix

| Call site | Текущая семантика | Наблюдаемость / health | Замороженное изменение и владелец |
|:--|:--|:--|:--|
| `CsvSourceMethod::listingLease()` | `use_proxy=false` сам создаёт direct; `true` передаёт exact crawler bindings + country в `ProxyResolver` для `crawl_listing` | `proxy_id` попадает в listing batch/fetch; каждый ответ и connection failure вызывает `reportOutcome()` | P5.3 заменяет bool на `profile|default|direct`, а direct создаёт только resolver; P5.5 доказывает fail-closed |
| `CsvSourceMethod::detailLease()` | union привязок active `use_proxy=true` crawlers реестра; пустой union сам превращает в direct | `prc_fetches.proxy_id`; `reportOutcome()` обновляет общий global/per-target health | P5.3 применяет deterministic mixed-profile rule и сохраняет typed source |
| `DocumentDownloader::documentLease()` | тот же derived union; пустой union сам превращает в direct | `prc_fetches.proxy_id`; `reportOutcome()` вызывается на ответ/connection failure | P5.3 централизует precedence/direct и добавляет source evidence |
| `Okpd2NodeSynchronizer::traverse()` | один unrestricted pool resolve с `crawl_listing`, без profile/default carrier | fetch evidence отсутствует; lease не передаётся в `reportOutcome()` | P5.3 явно проводит OKPD2 через sealed global-default/direct policy и outcome accounting; P5.5 покрывает acceptance |
| `PoolOutboundProxy` → `PrismGeminiProvider` | отдельный `llm` resolve; только `NoProxyAvailableException` даёт явный `AI_GEMINI_PROXY` fallback; direct отсутствует | env fallback логируется; Gemini generate/files/cache получают один `OutboundProxy` port | Не потребляет `CrawlerProxySettings`; P5.5 exact-name/tooling separation, P10 cross-cutting audit |
| `scripts/playwright-mcp.sh` | tooling-only `PLAYWRIGHT_MCP_PROXY_SERVER` превращается во временный mode-600 Playwright config | вне application resolver/health/fetch; config удаляется trap'ом | Остаётся отдельным carrier; P5.5/P12 проверяют отсутствие runtime consumption |

`ProxyResolver` уже фильтрует active/scope/country/exact IDs, global и per-target breaker до score,
а `ProxyHealthStoreRepository` сохраняет carrier'ы раздельно в `prc_proxies` и
`prc_proxy_targets`. `ProxyBindingResolver::forProcurement()` сейчас объединяет только active
`use_proxy=true` profiles; он не умеет различать future `profile/default/direct`. Постоянный
`prc_fetches` хранит только nullable `proxy_id`, поэтому current direct неотличим от отсутствия
маршрута, а profile/default после rotation не восстанавливаются.

## Consumer matrix

| Consumer | Замороженный вход P5.1 | Обязательное доказательство |
|:--|:--|:--|
| P5.2 | `spatie/laravel-settings ^3.9`, `prc_settings`, nullable `defaultProxyId`, explicit refresh | lock/package discovery, settings migration/repository и cross-process worker freshness |
| P5.3 | modes `profile|default|direct`, exact configured IDs, no availability fallback, typed source | listing/detail/document/OKPD2 datasets, source persistence и тот же health carrier |
| P5.4 | только proxy ID и safe identity в settings UI, `ProxyPermission::Update` | authorization, three-scope validation, отсутствие credential dehydration |
| P5.5 | полный runtime/tooling contract | no-fallback, mixed profiles, health/source evidence, LLM/Playwright exact-name separation |
| P10 | EXT-01–EXT-04 и call-site matrix | cross-cutting bypass/TLS/security audit без нового resolver |
| P12 | terminal P5.5 evidence | settings revision видна long-lived workers и входит в release/readiness receipts |

## Verdict

Spatie Settings уместен как typed, расширяемый и Laravel-13-compatible carrier. Для P5 он должен
хранить только nullable `default_proxy_id`, ссылающийся на существующий `prc_proxies`: raw proxy
URL/username/password в settings запрещены. Dedicated database repository использует
`prc_settings`, чтобы не размывать четыре domain prefixes. Long-lived Horizon читает значение
через refresh-aware read service; production cache допустим только с тестом cross-process refresh.
RAG:— (repo-grounded synthesis: D8, architecture prefixes and official evidence above).

D28/D29 остаются byte-stable: terminal P0 products и fresh official check не дают противоречия.
P5.2–P5.5 обязаны реализовать матрицы выше без скрытого availability fallback и без переноса
LLM/Playwright carriers в crawler default.

## Risks to preserve in execution

1. Existing `use_proxy=false` is explicit direct intent; silently migrating it to inherit-default
   changes egress. Preserve it as typed `direct`.
2. Configured profile/default proxy becoming unhealthy is not the same as «не настроен»: falling
   through to direct would bypass policy. Resolver must fail visibly.
3. A single default node must carry `crawl_listing`, `crawl_detail`, and `document_download` scopes;
   Filament validates this before save, resolver repeats fail-closed validation.
4. P0.5 оставил exact package/worker freshness unresolved; P5.1 закрыл package/API часть
   первичными источниками, а P5.2/P5.5 всё ещё обязаны доказать реальную cross-process freshness.
5. OKPD2 сейчас не сообщает outcome и не несёт profile/default source; этот gap нельзя потерять
   при общей миграции call sites в P5.3.

## Verification status

- RAG: Context7 ×2 + official GitHub latest release/tagged composer/README OK; Perplexity bridge
  вернул no-answer и был заменён primary-source Web fallback; repo/history inspection OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: nothing for P5.1; runtime cross-process freshness is declared
  downstream execution evidence P5.2/P5.5, not a fact claimed by this recon.
- Обходки/[POSSIBLE-DEFECT]: none.
