# HANDOFF — 2026-08-08 — after P7

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/xhigh

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high/xhigh — remediation затрагивает замороженный LLM schema-contract, старые persistence-тесты и разбор смешанного dirty worktree |
| Context | NEW SESSION — шаг-не-item |
| Суть | Part 2: независимо перепроверить завершённую P7 RED remediation и вынести GREEN/RED; P8.1 не запускать. |
| Команда | Вручную по Layout v2; дословная Part 2 команда сохранена ниже. Не вызывать `$ task:plan-audit` или `$ task:plan-exec`, пока Task-плагин остаётся v1. |

**Done:** owner-authorized Part 1 remediation завершена без запуска task-команд и без P8.1.
Все 8 failures закрыты; provider-schema сохранена byte-for-byte; четыре scope-drift path формально
приняты; восстановлены 5 item + 5 bookkeeping и 2 независимых regression-коммита. Проверки:
focused regressions 28/93, P7 focused 52/207, gold 214/214, Pint 1082, PHPStan 0, full suite
1 skipped / 1392 passed / 5856 assertions.

**Remaining:** только Part 2 — независимый повторный аудит P7 remediation. Лишь GREEN разрешает
переключить следующий handoff на P8.1; эта сессия останавливается до него.

**Sources of truth:** `phases/P7/P7.md` · `phases/P7/P7.1.md`–`P7.5.md` · соответствующие `P7.*.state.md` · `journal.jsonl`.

**Open risks:** независимый audit ещё не вынес verdict. Смешанный dirty worktree вне P7 сохранён.
Bundles P7.1–P7.5 по-прежнему дают документированные `unresolvable_entry`; complete-plan
`plan-lint` fail-closed на сохранённых 38 read-admission errors. Compose metadata видит лишний
created Postgres-контейнер, хотя canonical `agelto_postgres` healthy; тесты надёжно выполнялись
через canonical containers от root. Закрытые/future items и эти admission defects не переписывать.

**Remediation facts:** `RequiredFieldMissing` остаётся validator-origin и исключена из provider
schema атрибутом; old golden не переснимался. `DatasetExample.php` принят P7.2,
`AnalysisResource.php`/`EstimateResource.php` — P7.3, `EstimateRow.php` — P7.4. Commit pairs:
P7.1 `dacd9b54`/`926f5c67`; P7.2 `43e1a4ae`/`7b0079a8`; P7.3 `566703ac`/`62dc0b69`;
P7.4 `08efe01c`/`c3123eb2`; P7.5 `5be45334`/`255f7f1c`; independent `38e84c1b`/`08c219c4`.
Workaround окружения: Compose требует временный `MINIO_ROOT_PASSWORD`; тесты запускаются от root
из-за прав на cache. Перед plan-design/reopen прочитать `artifacts/legacy-v1-open-questions.md`.

## Последовательность новых сессий

Эта цепочка — обязательный carrier между сессиями. Часть 1 не запускает P8.1 и перед завершением
обновляет этот handoff фактическими изменениями/коммитами/проверками, сохраняет в `Next` ручной
запуск части 2 и печатает пользователю команду части 2 дословно. Часть 2 стартует только после
успешного завершения части 1 и независимо перепроверяет её результат.

### Часть 1 — remediation

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md and plans/2026.08.06-CRAWL-HARDEN/phases/P7/P7.md. This is Layout v2. Execute only the owner-authorized P7 RED remediation recorded there; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P8.1. You are explicitly authorized to modify application code, tests and P7 remediation bookkeeping needed to fix all eight reproducible failures, scope drift and commit deviations. Preserve unrelated dirty worktree changes and do not silently rewrite closed P7.1-P7.5 specifications or state carriers. Resolve JsonSchemaGeneratorTest x2 and EstimateQualityPersistenceTest x4 with an evidence-backed RowQualityReason provider-schema decision; resolve ProxyResourceTest and TextGridRendererTest independently; formally account for DatasetExample.php, AnalysisResource.php, EstimateResource.php and EstimateRow.php; recover atomic attributable commits without sweeping unrelated changes. Run test-DB preflight, focused regressions, all P7 focused tests, estimate:fields:audit --source=gold --json, Pint, PHPStan and the full suite. Before and after bookkeeping changes resolve the Swissknifeman root with codex plugin marketplace list --json, run full plan-lint, then regenerate/check state --all, decisions and status in dependency order; run bundles P7.1-P7.5 and preserve documented read-admission defects. Update P7 Phase Handoff, handoff.md and journal.jsonl with exact facts. Before finishing, keep Next as a manual frontier/xhigh launch, preserve the Part 2 command from this handoff, and print that exact Part 2 codex command to the user. Stop before P8.1.'
```

### Часть 2 — независимый повторный аудит

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P7/P7.md and P7.1-P7.5 state carriers. This is Layout v2. Independently re-audit only the completed P7 remediation; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P8.1. Verify every prior RED finding: six P7.4 regressions, both external full-suite failures, no-op, the four undeclared paths, dirty-worktree attribution and item/bookkeeping commits. Run the v2 bundles and the marketplace-resolved plan-lint plus state --all, decisions and status regenerate/check sequence from AGENTS.md. Run test-DB preflight, focused regressions, all P7 focused tests, estimate:fields:audit --source=gold --json, Pint, PHPStan and the full suite. Apply automatic fixes only to audit/handoff bookkeeping; if application or closed-item defects remain, record a new scoped remediation. Write a GREEN or RED Phase Handoff and journal event with exact evidence, update handoff.md, and stop before P8.1. Only a GREEN verdict may set the next handoff target to P8.1.'
```
