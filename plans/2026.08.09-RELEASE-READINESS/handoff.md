# HANDOFF — 2026-08-12 — after P8.3

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P8.4

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model class | implementation |
| Effort | high — repositories, precedence and Filament authorization |
| Capabilities | — |
| Context | continue-root |
| Суть | Выполнить P8.4: repository-backed precedence, race-safe unknown, exact EIS seed и authorized Filament status-mapping surface. |
| Команда | — |

#### Provider-neutral launch-block

```text
task:plan-run 2026.08.09-RELEASE-READINESS P8.4
```

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P8.4'
```

**Done:** P8.3 закрыт product-коммитами `44168b91` и `d079f5d0`: migration 100092 сохраняет registry
rows, вводит ровно один FK-backed applicability (`platform` или `registry`), partial unique
indexes и lossless rollback guard. Добавлены typed enum/model/DTO/factory contract и focused
migration tests. Проверены focused Pest, Pint, PHPStan, Task views, strict citations
и `git diff --check`. Terminal delivery record был локально исправлен без изменения продукта;
это process deviation. Downstream records для P8.4/P8.5 материализованы с outcome `unknown`;
это ожидаемое состояние будущих consumer carriers и не блокирует закрытие.

**Remaining:** P8.4–P8.5, затем обязательный phase audit/close по phase policy.

**Sources of truth:** `plans/2026.08.09-RELEASE-READINESS/plan.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.3.state.md` ·
`plans/2026.08.09-RELEASE-READINESS/findings/P8-platform-status-extension-recon-2026-08-09.md` ·
`plans/2026.08.09-RELEASE-READINESS/research/10-P8-execution-design.md` · D35 · D36

**Open risks:** P8.4 должна сохранить exact-over-platform precedence, не ловить любой
`QueryException` как duplicate и не ослабить существующие permission guards. Downstream остаётся
`unknown`, пока P8.4/P8.5 не создадут свои executable carriers; downstream RED отсутствует.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: реальная вторая площадка, global raw-stage scope и generic predicate engine остаются вне P8.
- owner_note: перенос содержимого БД между серверами не выполняется: записей для переноса и второго сервера нет.
- open_questions: —
