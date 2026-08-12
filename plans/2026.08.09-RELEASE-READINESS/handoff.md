# HANDOFF — 2026-08-12 — after P8.2

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P8.3

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model class | implementation |
| Effort | high — общий runtime/config/Form hotspot |
| Capabilities | — |
| Context | continue-root |
| Суть | Выполнить P8.3 schema migration для status mappings с lossless rollback и SQL-contract доказательствами. |
| Команда | — |

#### Provider-neutral launch-block

```text
task:plan-run 2026.08.09-RELEASE-READINESS P8.3
```

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P8.3'
```

**Done:** P8.2 закрыт product-коммитом `b27a5c09`: typed immutable exact-platform adapters
заменили global source/filter maps, EIS UI вынесен в provider form, а runtime/config/factory
consumers fail closed. Проверены 132 Pest / 446 assertions, PHPStan relevant paths, Pint,
config-cache roundtrip, legacy/generic-branch scans и `git diff --check`. Downstream records
для P8.5/P9.3/P11.3 материализованы с outcome `unknown`; это ожидаемое состояние будущих
consumer carriers и не блокирует закрытие.

**Remaining:** P8.3–P8.5, затем обязательный phase audit/close по phase policy.

**Sources of truth:** `plans/2026.08.09-RELEASE-READINESS/plan.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.2.state.md` ·
`plans/2026.08.09-RELEASE-READINESS/findings/P8-platform-status-extension-recon-2026-08-09.md` ·
`plans/2026.08.09-RELEASE-READINESS/research/10-P8-execution-design.md` · D35 · D36

**Open risks:** downstream остаётся `unknown`, пока P8.3–P8.5/P9.3/P11.3 не создадут свои
executable carriers; это ожидаемый future-consumer state, downstream RED отсутствует.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: реальная вторая площадка, global raw-stage scope и generic predicate engine остаются вне P8.
- owner_note: P8.4 явно исключает перенос содержимого БД между серверами: записей и второго сервера нет; схемная migration P8.3 не отменяется.
- open_questions: —
