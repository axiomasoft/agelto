---
audience: dev
type: adr
status: draft
---

# ADR 0005: `is_protected` — сидовые промты read-only в админке

- Status: Accepted
- Date: 2026-07-07
- Источник: `plans/EST-AI/plan.md` §5 Decision Log, D17, D44

## Context

Тексты промтов v1 жили ТОЛЬКО в БД (`plt_prompt_rules`, Filament MarkdownEditor) — файлов-источников
не было, история правок и связь с git отсутствовали (research/B). Требование владельца v2: файлы
обоих корней канона (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) под git —
источник истины; при этом операторы должны иметь возможность
дополнять/корректировать промты через админку без правки кода (обучающий контур,
`docs/03_Dev/ai/training-loop.md`).

## Decision

`PromptSeeder` читает оба корня канона и upsert'ит в `llm_prompts` по `(key, version)`
(`php artisan db:seed` достаточно для развёртывания). Новая колонка `is_protected` (bool) помечает
сидовые записи как read-only в админке — операторы создают ТОЛЬКО новые (не редактируют канон)
дополняющие/корректирующие записи.

Реализация RBAC-обхода (D44, дополняет D17): штатный `ResourceGate::check()` AzGuard решает
авторизацию грубо по карте `FilamentDiscovery::subjects()`, без учёта конкретной записи —
недостаточно для правила «is_protected → нельзя редактировать конкретную запись, но право у роли
есть». `PromptResource` исключён из `config('az-guard-filament.exclude.resources')` — тогда
`ResourceGate::check()` для `Prompt` возвращает `null`, штатный `Gate::policy()` резолвится в
`PromptPolicy`, которая перепроверяет И грубое право (`hasPermission()`), И `!is_protected`.

## Consequences

- Плюс: канон из git не может случайно разъехаться с БД через админку — редактирование канона
  только через PR.
- Плюс: `PromptPermission`/`OperatorRole` не потребовали изменений — RBAC-обход локализован в одной
  политике, а не в новой грануляции ролей.
- Минус (принят осознанно): `PromptResource` — единственный ресурс с этим паттерном композиции
  «коарс-право + запись-специфичный чек»; при появлении второго такого случая стоит рассмотреть
  вынесение паттерна в переиспользуемый trait/base-policy (Pending Work, не сделано в этом плане).

## Смежное

- `../ai/prompts.md` — файлы → сид → композиция промта целиком.
- `../admin-panel.md` — устройство AzGuard-гейта для Procurement-домена.
