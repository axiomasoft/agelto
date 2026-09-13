---
audience: dev
type: adr
status: living
---

# ADR 0002: Prism за фасадом `LlmProvider`

- Status: Accepted
- Date: 2026-07-07
- Источник: `plans/archive/2026.07.07-EST-AI/plan.md` §5 Decision Log, D8, D14; `plans/archive/2026.07.07-EST-AI/research/G-llm-transport-training.md` §1

## Context

Транспорт для LLM-вызовов нужен был выбрать между first-party `laravel/ai` (анонс февраль 2026,
v0.8.1 на момент решения — всё ещё 0.x/preview, Gemini `cachedContents` только через generic
`providerOptions()`, кастомный провайдер-контракт не документирован) и `prism-php/prism` (v0.91.0,
~2 года прод-использования, typed Gemini caching/files, документированная driver-архитектура).

## Decision

Транспорт — Prism, спрятанный за собственным доменным контрактом `LlmProvider`
(`app/Services/Llm/Contracts/LlmProvider.php`), а не вызывается напрямую из бизнес-кода. Контракт
держится адаптер-совместимым с `laravel/ai` — пересмотр возможен после его выхода в 1.0.
Кэширование — capability-based (D14): промт всегда prefix-stable, опциональный cache-hint
интерпретируется конкретным адаптером (`SupportsContextCaching` — Gemini-only сегодня).

## Consequences

- Плюс: typed Gemini Files API + context caching без риска на 0.x/preview-миграции `laravel/ai`.
- Плюс: смена транспорта (Prism → laravel/ai после 1.0, или добавление третьего провайдера) не
  трогает домен — только `Services/Llm/Providers/*`.
- Минус (принят осознанно): Prism тоже 0.x — версия зафиксирована, обновления через явный
  `composer update` с ревью changelog, не автоматически.

## Смежное

- `../llm-providers.md` — контракт, роутинг, capability-матрица целиком (Д6).
- ADR [0004](0004-jsonb-for-non-estimate-results.md) — генерализация результата под контракт.
