# HANDOFF — 2026-08-10 — after P0.2

**Next:** design-item: task:plan-design 2026.08.09-RELEASE-READINESS P0.2

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | cold-start — plan-step |
| Суть | Добавить P0.1 inventory в declared inputs P0.2 и пересобрать bundle без изменения результатов сравнения. |
| Команда | `$ task:plan-design 2026.08.09-RELEASE-READINESS P0.2` |

**Done:** P0.2 зафиксировал неизменный provenance трёх dirty reference trees, но не создавал
comparison deliverables: P0.1 inventory отсутствует из declared Inputs/Required Reads P0.2.

**Remaining:** plan-design должен добавить `findings/P0-agelto-inventory.md` как input/required
read P0.2 и обновить digest-qualified bundle; после этого P0.2 выполняется заново в
read-only режиме.

**Sources of truth:** `plan.md` · `phases/P0/P0.md` · `phases/P0/P0.2.md` ·
`phases/P0/P0.2.state.md` · `findings/P0-agelto-inventory.md` · `journal.jsonl` · `roadmap.md`.

**Open risks:** Spec P0.2 требует comparison с Agelto, но его bundle declares только reference
map, P0.1 state и execution design; P0.1 state не содержит inventory evidence. Перечисленные
reference trees read-only и не менялись во время снятия provenance.

**Workarounds/Deferred/Open questions:** —

## Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS P0.2'
```

```text
$ task:plan-design 2026.08.09-RELEASE-READINESS P0.2

РЕЖИМ: remediation
ВХОД: phases/P0/P0.2.md · bundles/P0.2.json · findings/P0-agelto-inventory.md · journal.jsonl
СКОУП: 1. Добавить inventory P0.1 в Inputs и Required Reads P0.2. 2. Пересобрать generated bundle/views. 3. Сохранить read-only границы и Validation P0.2.
НЕ ТРОГАТЬ: reference repositories, P0.2 comparison deliverables, application/Docker/schema/docs вне плана.
```
