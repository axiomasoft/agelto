# HANDOFF — 2026-08-08 — after P7

**Next:** ЗАПУСК ВРУЧНУЮ: P8.1, frontier/high

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — P8.1 принимает несущие schema-решения для P8.2/P8.3 |
| Context | NEW SESSION — manual Layout-v2 item |
| Суть | P8.1: карта поглощения, счётный снимок и решение Q16. Текущая сессия P8.1 не запускала. |
| Команда | Вручную по Layout v2. Не вызывать `$ task:plan-audit`/`$ task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.1.md and plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.1.state.md. This is Layout v2. Execute only P8.1 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P8.2. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes and stop after P8.1 validation and Layout-v2 bookkeeping.'
```

**Done:** независимый повторный аудит P7 remediation — **GREEN**. Все восемь RED failures
закрыты: focused regressions 28/93; union item-validation тестов P7.1–P7.5 50/200;
training-UI boundary 3/75; gold 214/214 по каждому из пяти полей; Pint 1082; PHPStan 0; полный
suite 1 skipped / 1392 passed / 5856 assertions (72.91s). Application/closed-item defects не
найдены, новая remediation не требуется.

**Commit/scope evidence:** P7.1 `dacd9b54`/`926f5c67`; P7.2 `43e1a4ae`/`7b0079a8`; P7.3
`566703ac`/`62dc0b69`; P7.4 `08efe01c`/`c3123eb2`; P7.5 `5be45334`/`255f7f1c`; independent
proxy/text-grid `38e84c1b`/`08c219c4`; remediation bookkeeping `a8467304`. Все 13 коммитов
непустые и линейные. Четыре бывших undeclared paths приняты и атрибутированы: `DatasetExample.php`
→ P7.2, `AnalysisResource.php`/`EstimateResource.php` → P7.3, `EstimateRow.php` → P7.4. Закрытые
P7.1–P7.5 specs после своих bookkeeping-коммитов не менялись.

**Dirty-worktree attribution:** P7/remediation commits не захватили чужие изменения. Единственное
пересечение текущего tracked dirty diff с P7 paths — `docs/03_Dev/document-pipeline.md`, где
остаётся только P6.5 hunk про событие завершения закупки; untracked dirty paths с P7 commit-set не
пересекаются. Остальной смешанный dirty worktree сохранён.

**Plan evidence:** P7.1–P7.5 bundles воспроизводят те же пять документированных
`unresolvable_entry`. Marketplace-resolved complete-plan `plan-lint` fail-closed на тех же 38
read-admission errors. `state --all` 59, `decisions` 4 и `status` 1 regenerate/check — GREEN.
Эти известные defects не переписывались и не обходились.

**Remaining:** P8.1–P9. До начала P8.1 прочитать `artifacts/legacy-v1-open-questions.md`; legacy-v1
snapshots не использовать как live carriers.

**Sources of truth:** `phases/P7/P7.md` · `phases/P7/P7.1.md`–`P7.5.md` · соответствующие
`P7.*.state.md` · `journal.jsonl` · следующий item `phases/P8/P8.1.md` + `P8.1.state.md`.

**Open risks/workarounds:** Compose metadata видит лишний created Postgres-контейнер, canonical
`agelto_postgres` healthy; Compose требует временный `MINIO_ROOT_PASSWORD`; тесты запускаются от
root из-за прав на cache. Полный plan-lint остаётся красным на сохранённых 38 staged defects.
