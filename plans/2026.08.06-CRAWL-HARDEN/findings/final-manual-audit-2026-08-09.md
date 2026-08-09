# Final manual Layout-v2 audit — 2026-08-09

## Verdict

**RED.** Прикладное поведение текущего dirty worktree проходит все воспроизводимые
гейты, но план не может считаться доставленным: девять закрытых items не имеют
обязательных item/bookkeeping-коммитов, и clean `HEAD` не содержит заявленные P6/P9
результаты.

## Audit scope and snapshot

- Прочитаны `AGENTS.md`, `plan.md`, `status.md`, `handoff.md`, все 174 на момент постфлайта
  JSONL-события и все 127 v2-носителей P1–P9: 9 фаз, 59 item-specs, 59 item-states.
- Legacy-v1 provenance был исключён и не использовался; один поздний широкий structural grep
  вернул только заголовок legacy handoff, без чтения его содержимого. `task:plan-audit`,
  `task:plan-exec` и другие `task:*` не вызывались.
- Marketplace root: `/home/vostrikov/projects/packages/swissknifeman`.
- Git anchor: `HEAD 236d95c3`; исходный worktree — 387 status entries. Аудит не менял
  application code, docs или закрытые item-specs.
- Конституция: Layout-v2 bridge и live path из `AGENTS.md`; Execution Rules и DoR плана;
  `Files`/`Validation`/`Deliverables` каждого item; plan/spec profile и шкала severity×label
  `quality:artifact-review`.

## Findings

### F1 — issue(Blocker): девять terminal items не доставлены в Git

**Anchors:** `phases/P6/P6.4.md:81`, `phases/P6/P6.4.md:84`, `phases/P6/P6.5.md:116`,
`phases/P6/P6.5.md:121`, `phases/P9/P9.2.md:65`, `phases/P9/P9.2.md:68`,
`phases/P9/P9.8.md:72`, `phases/P9/P9.8.md:75`.

P6.4, P6.5 и P9.2–P9.8 терминальны, но `git log --since=2026-08-06` не находит ни одного
коммита для этих девяти items. Это не процессная эстетика: `git cat-file -e HEAD:<path>`
подтверждает отсутствие в clean `HEAD`:

- `app/Events/Procurement/ProcurementSucceeded.php`;
- `app/Services/Procurement/ProcurementOutcomeEvaluator.php`;
- `tests/Feature/Filament/ProcurementHistoryTest.php`;
- `tests/Feature/Procurement/ProcurementSucceededEventTest.php`;
- `tests/Unit/Docs/DocsIntegrityTest.php`;
- `docs/03_Dev/ai/estimate-extraction.md`;
- `docs/meta/structure.md`.

Одновременно P8 уже зафиксировал `succeeded_at` и его partial index в
`database/migrations/2026_07_04_100011_create_prc_procurements_table.php:70` и `:94`.

**Failure scenario:** clone/CI/релиз на clean `HEAD` получает схему P6.5, но не получает событие,
его evaluator, UI и тесты; финальная документация также остаётся в рабочем каталоге.

**Constitution rule:** item `Deliverables` обязывает сдать item-коммит и bookkeeping-коммит;
`plan.md:44` требует закрывать items последовательно, а Layout-v2 handoff должен вести к
воспроизводимой доставке.

### F2 — issue(Major): P9.8 имеет системный Scope↔Files drift

**Anchors:** `phases/P9/P9.8.md:20`, `phases/P9/P9.8.md:31`, `phases/P9/P9.8.md:38`,
`phases/P9/P9.8.state.md:15`.

Scope требует прочитать и переоценить `status:` всего дерева, но `Files` перечисляет в основном
index-носители. Фактический `git diff -U0 -- docs` показывает 46 переходов `status: draft → living`,
включая ADR, docker-, process- и runbook-файлы, которых в `Files` нет. В mixed dirty worktree без
item-коммита нельзя доказать, что каждый hunk принадлежит P9.8, а не предсущей работе.

**Failure scenario:** remediation стейджит весь docs-diff и молча захватывает чужие ханки, либо
отбрасывает реальную P9.8 ревизию как внескоупную.

**Constitution rule:** DoR требует полного согласования Scope/Files; `plan.md:46` запрещает
импровизировать при расхождении плана с фактом.

### F3 — issue(Major): item-state P3 противоречит фазовому handoff

**Anchors:** `phases/P3/P3.1.state.md:14`, `phases/P3/P3.1.state.md:17`,
`phases/P3/P3.2.state.md:14`, `phases/P3/P3.md:127`, `phases/P3/P3.md:134`,
`phases/P3/P3.4.state.md:14`, `phases/P3/P3.md:139`, `phases/P3/P3.md:144`,
`phases/P3/P3.8.state.md:14`, `phases/P3/P3.8.state.md:15`, `phases/P3/P3.md:164`,
`phases/P3/P3.md:167`.

Generated state оставляет P3.1/P3.2 `🟠`, хотя фазовый аудит переоценил inherited D112 baseline
в `🟢`; P3.4 остаётся `🟢`, хотя handoff требует `🟠` за material Files drift; P3.8 остаётся
`🟢` и заявляет 17 тестов, хотя handoff фиксирует 12 и `🟠`. Прикладной дефект P3.8 позднее закрыт
P3.10, но процессные deviation не исчезли.

**Failure scenario:** status и новый исполнитель доверяют генерируемым item-state и получают
противоположные verdict/test facts относительно фазового source of truth.

**Constitution rule:** Layout-v2 live path требует непротиворечивой цепочки
`journal → item-state → phase handoff → status`. Закрытые item-specs этим аудитом не менялись;
наивное добавление correction-attempts также расширяет legacy read-admission failures на P3,
поэтому источник должен быть согласован v2-aware механизмом, а не ручной правкой projection.

### F4 — issue(Minor): master-plan meta считает 58 items вместо 59

**Anchor:** `plan.md:16`.

Живое дерево и `state --all` имеют 59 specs/states; Meta всё ещё называет 58.

**Failure scenario:** ручная сверка закрытия ищет «лишний» item или принимает неполный инвентарь.

**Constitution rule:** structural completeness и internal consistency plan/spec profile. В этом audit `plan.md`
не изменялся из-за ограничения записи; число исправляется отдельным bookkeeping-изменением.

### F5 — issue(Minor): generated Owner Gates смешивает green status и историческую friction

**Anchors:** `phases/P1/P1.4.state.md:14`, `phases/P1/P1.4.state.md:17`, `status.md:319`,
`status.md:321`.

P1.4 и ещё несколько items имеют `🟢 Done`, `Escalation Needed: no`, но попадают в `Owner Gates`,
потому что generator проецирует `friction=[deviation]` как `Known Deviations`, даже когда отклонение
было устранено до green-закрытия.

**Failure scenario:** новая сессия ждёт owner decision там, где решение не требуется, или игнорирует
весь раздел как шум.

**Constitution rule:** однозначность канонического status и handoff. Это upstream projection defect;
`status.md` вручную не правится.

## No-op, scope and commit audit

- Все 41 явно упомянутые 8-hex commit anchors резолвятся в commits.
- Среди commits с item-ID в subject нет empty-tree/no-op commits.
- P3.6 исторически помечен `friction=[no-op]` (`journal.jsonl:56`), но это ложная метка:
  item-коммит `d6329cdb` непуст и доставляет витрину истории. Фактического no-op нет.
- Ранее зафиксированный scope drift P2/P3/P4/P7 назван в фазовых handoff и не скрыт.
  P7 re-audit commit-set непуст; все 13 anchors существуют. Новый неатрибутируемый drift — F2.
- P9.4 явно не импортировал старый `prc_search` materialized-view claim без code-carrier;
  это названо в `P9.4.state.md:15` и считается explicit deviation, а не скрытой потерей.

## Command evidence

### Preflight before bookkeeping

- `codex plugin marketplace list --json` → Swissknifeman root resolved.
- `plan-lint.py plans/2026.08.06-CRAWL-HARDEN` → exit 1, 28 errors:
  27 известных read-admission/parser diagnostics + один новый schema defect
  `journal.jsonl:172` (дефис вместо точки в plan ID). Schema defect исправлен bookkeeping-правкой;
  известные diagnostics не скрывались и future items не переписывались.
- `plan-views.py state --all --check` → 59 checked, exit 0.
- `plan-views.py decisions --check` → 4 checked, exit 0.
- `plan-views.py status --check` → 1 checked, exit 0.

### Postflight after bookkeeping

- `plan-lint.py plans/2026.08.06-CRAWL-HARDEN` → exit 1, ровно 27 сохранённых
  read-admission/parser diagnostics, 0 warnings; новых journal/handoff diagnostics нет.
- Regenerate в dependency order → state 59, decisions 4, status 1.
- `state --all --check` → 59 checked; `decisions --check` → 4 checked;
  `status --check` → 1 checked; все три exit 0.

Сохранённый fail-closed inventory (27) не свёрнут в общий «known failure»:

- 12 реальных, но унаследованных до этого аудита plan-input defects: prose-only skills/paths в
  P5.3 (1), P6.5 (1), P7.1 (3), P7.2 (1), P7.3 (3), P7.4 (2), P7.5 (1);
- 14 реальных stale-source defects после удаления `docs/specs/v2/**`: P8.4 (1), P9.1 (2),
  P9.3 (2), P9.4 (8), P9.5 (1);
- 1 upstream parser-lag diagnostic P9.7: legacy whole-file check считает 29,603 токена и не
  учитывает уже зелёные bounded v2 section selectors. Только этот diagnostic классифицирован как
  tool limitation, а не plan defect.

Ни один defect не был waived, скрыт или исправлен переписыванием закрытого item-spec.

### Reproduced validation

- DB preflight: `agelto_postgres` healthy; `pg_isready` accepting; `agelto_test` exists and matches
  `_test`; all 63 migrations are `Ran`.
- P6.4/P6.5 focus: `--filter='ProcurementHistory|ProcurementSucceeded|ProcurementOutcome'` →
  13 passed / 30 assertions.
- P9: `--filter='DocsIntegrity'` → 4 passed / 16 assertions.
- Full suite, ordinary app user → exit 2, 284 failed / 1 skipped / 1112 passed / 4720 assertions;
  sampled failures are `FilesystemIterator ... Permission denied` under root-owned test disks.
- Full suite, documented `-u root` workaround → exit 0, 1 skipped / 1396 passed / 5872 assertions.
  Therefore the non-root red is inherited environment state, not a plan code defect.
- Pint → 1040 files, exit 0; PHPStan → no errors, exit 0; `git diff --check` → exit 0.
- P8 counters → 63 migration files; 70 public ordinary/partition-parent tables; `prc_daily_stats`,
  `prc_regions`, `prc_ignore_rules` absent; `prc_ignored_items` present.
- P9 final JSON parses; live recount → 74 markdown, 0 without frontmatter, one explained draft;
  DocsIntegrity proves 210 relative links and zero broken links.

## Bookkeeping repairs made by this audit

Application code, docs and closed item-specs were not changed. The audit only:

1. fixed the malformed `plan_id` at historical journal line 172;
2. replaced the contradictory root handoff and the placeholder P6 phase handoff;
3. appended this manual `audit-red` event and regenerated/checked v2 views.

## Next-session launch block

```bash
cd /home/vostrikov/projects/agelto/agelto
codex plugin marketplace list --json
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py plans/2026.08.06-CRAWL-HARDEN
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py state --plan-dir plans/2026.08.06-CRAWL-HARDEN --all --check
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py decisions --plan-dir plans/2026.08.06-CRAWL-HARDEN --check
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py status --plan-dir plans/2026.08.06-CRAWL-HARDEN --check
git status --short --branch
```

Затем вручную, без `task:*`:

1. Снять patch-level inventory только P6.4/P6.5 paths; не стейджить чужие modified files
   целиком. Создать отдельные P6.4/P6.5 application/test commits; записать отклонение,
   что P6.5 schema уже атомарно живёт в более раннем P8.2 commit.
2. Разделить P9.2–P9.8 по семантике и Files. Для 46 frontmatter hunks использовать patch staging
   и явно записать F2 scope-admission; закрытые item-specs не менять.
3. Доказать целостность clean `HEAD` через `git cat-file`/temporary detached worktree, затем
   повторить P6 focus, DocsIntegrity, Pint, PHPStan и root full suite.
4. Согласовать P3.1/P3.2/P3.4/P3.8 journal/state с уже каноническим фазовым handoff через
   v2-aware projection path; закрытые item-specs и generated states вручную не менять.
5. Исправить счётчик 58→59 отдельным master-plan bookkeeping-изменением.
6. Дописать remediation-событие в `journal.jsonl`, обновить этот отчёт и handoff, затем
   выполнить postflight в порядке `plan-lint` → `state --all` → `decisions` → `status` → `--check`
   и независимый ручной re-audit.

До закрытия F1/F2/F3 вердикт не повышается до ATTENTION/GREEN.
