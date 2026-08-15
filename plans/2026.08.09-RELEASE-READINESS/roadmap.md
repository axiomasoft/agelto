# Roadmap исполнения — 2026.08.09-RELEASE-READINESS

<!-- Живая карта «что запускать дальше», не второй план. Roadmap не переопределяет Routing §3,
     item contracts, owner gates или material-review trigger. -->

**Обновлён:** 2026-08-15 · **Соответствует plan.md:** migration-version 0.1.14 · 13 фаз · 84 items · D89/D90

## Правило группировки

- Terminal P0–P11 и P12.1 свёрнуты; их historical route/evidence остаются в §3, state и journal.
- D89/D90 объединяют P12.2–P12.7 в `B12-PREP`: items adjacent, phase-local, имеют один
  implementation/high manual route, общий RAG/profile/operator-doc/script context и последовательный
  producer→consumer contract.
- Per-item lifecycle и commits сохраняются внутри batch; batch не пересекает phase close/audit.
- Real host/DNS/GitHub/registry/CI/TLS/deploy/backup/restore authority не гейтит `B12-PREP` и
  принадлежит отдельному будущему operator rehearsal plan.

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|:--|:--|:--|:--|:--|:--|
| P0.0–P9.6 | closed | — | §3 Routing | — | terminal history preserved |
| P10.1–P10.6 | closed-deviations | — | §3 Routing | — | 5 `🟢`, 1 `🟠`; exact deviation in state/journal |
| P11.1–P11.6 | closed-deviations | — | §3 Routing | — | 5 `🟢`, 1 `🟠`; exact deviation in state/journal |
| P12.1 | closed-deviations | — | implementation/high | — | historical local host carrier; D89 does not rewrite evidence |
| P12.2–P12.7 | B12-PREP | `task:plan-run … P12.2-P12.7` | implementation/high | — | local scripts/docs/templates/fixtures; runtime proof excluded |

## B12-PREP — локальный production-preparation package

| Параметр | Значение |
|:--|:--|
| Batch | B12-PREP |
| Model class | implementation |
| Effort | high — security/deploy/recovery scripts и cross-carrier docs требуют широкого, но запечатанного исполнения |
| Capabilities | — |
| Context | cold-start-root: design-root-terminal |
| Суть | Последовательно закрыть P12.2–P12.7 локальными contract gates без external mutation. |

**Native Codex command:**

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P12.2-P12.7'
```

## Следующий запуск

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P12.2-P12.7

| Параметр | Значение |
|:--|:--|
| Batch | B12-PREP |
| Model class | implementation |
| Effort | high — security/deploy/recovery scripts, source research и cross-carrier docs требуют полного repair-forward исполнения |
| Capabilities | — |
| Context | cold-start-root: design-root-terminal |
| Суть | Последовательно довести весь локально достижимый P12.2–P12.7 scope до GREEN; external-state absence не блокирует batch. |

**Native Codex command:**

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P12.2-P12.7'
```

## Гейты владельца (сводно)

| Где | Что утверждает/предоставляет | Блокирует |
|:--|:--|:--|
| Current P12 | — | `B12-PREP` не имеет external/owner gate |
| Future operator rehearsal plan | host, DNS, GitHub enrollment, registry/CI, TLS, backup/alert destination и destructive rehearsal authority | только future runtime proof и D45 verdict |
