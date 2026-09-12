# Индекс плана — 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION

**Обновлён:** 2026-09-10

## Карта директорий

| Путь | Назначение |
|:--|:--|
| `plan.md` | stable core: Meta, Context, Rules, Routing, Decision pointer |
| `status.md` | generated status projection |
| `phases/<Pn>/<Pn>.md` | stable Phase Context и dependencies |
| `phases/<Pn>/<Pn.m>.md` | stable item specification |
| `phases/<Pn>/<Pn.m>.state.md` | generated volatile item state |
| `bundles/<Pn.m>.json` | ordered digest-qualified executor inputs |
| `decisions/` | accepted decisions и generated views |
| `handoff.md` | rolling semantic Next |
| `roadmap.md` | execution grouping и owner gates |
| `journal.jsonl` | append-only Task history |
| `brief/` | слой 0: owner brief/refinements |
| `findings/` | слой 1: source evidence |
| `research/` | слой 2: design synthesis |
| `artifacts/` | исходные audit/remediation reports и execution evidence |

## Провенанс

| Источник | Слой | Питает | Суть |
|:--|:--|:--|:--|
| `brief/00-brief.md` | brief | P0–P4 | Полный owner intent и ограничения design-only |
| `findings/00-source-synthesis.md` | findings | P0–P4 | A1–A9/B1–B3/NEW-1/NEW-2 и pre-check evidence |
| `research/00-user-intent.md` | research | P0–P4 | Трассировка каждой мысли брифа в carrier |
| `research/01-remediation-architecture.md` | research | P0–P4 | Инварианты, dependency chain и coupling hotspots |

## Ключевые артефакты

Исходные audit/remediation reports находятся в `artifacts/`; план не экспортирует файлы в корень репозитория.
