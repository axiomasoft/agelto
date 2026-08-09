---
id: D29
date: 2026-08-09
status: accepted
item: P5.3
items: [P5.1, P5.2, P5.3, P5.4, P5.5, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Profile semantics становятся typed: `CrawlerProxyMode = profile|default|direct`; существующие
`use_proxy=true` мигрируют в `profile`, false — в `direct`, новые профили по умолчанию наследуют
`default`. Вся конфигурационная precedence исполняется внутри `ProxyResolver`: exact profile IDs →
exact default ID → typed direct только при отсутствующем default; explicit direct остаётся opt-out.
Настроенный, но недоступный/unscoped proxy fail-closed и не проваливается ниже. `ProxyRouteSource`
попадает в lease, structured log и `prc_fetches.proxy_source`; global/per-target health не меняются.
RAG:— (repo-grounded: D8, current direct intent and `research/07-P5-execution-design.md`).

## Почему

Bool не различает наследование и прямой opt-out, а availability fallback незаметно меняет egress и
обходит breaker. Typed source делает profile/default/direct проверяемыми без второго resolver или
health carrier и сохраняет существующую семантику уже созданных профилей.

## Consequences

Миграция и все listing/detail/document/OKPD2 call sites меняются атомарно; mixed profiles получают
явное aggregate precedence. LLM pool/env и Playwright MCP остаются отдельными carriers. Любое желание
фолбэчить при runtime outage требует superseding D с ценой policy bypass, а не локального catch.
