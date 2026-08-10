# P1 audit 2 remediation design

## Sources

- `phases/P1/P1.md` → последний `## Audit P1 — 2026-08-10` — F6–F10 и anti-scope.
- `phases/P1/P1.1.state.md` … `P1.6.state.md`, `journal.jsonl:90-102` — текущие closure carriers.
- `docker/tests/p1-release-contract.sh`, `docker/Makefile`, `docker/tests/make-contract.sh` и
  `docs/03_Dev/commands.md` — product/test/docs hotspot.
- D64/D65 и `research/16-P1-audit-remediation.md`/`17-P1.6-downstream-retry-design.md` —
  запечатанные решения первого reopen.

## Root cause and chosen anchor

`44a334ac` прошёл все product assertions, но не полный P1.6 carrier: его Task bundle view был stale.
Поздний `f7f45081` уже содержал fresh plan views, поэтому следующий product-only commit может
повторить всю исходную `TASK_ROOT=… bash docker/tests/p1-release-contract.sh` без изменения plan
inputs. P1.7 выбирает именно этот путь: удалить aliases отдельным product commit и сделать его новым
clean full-Validation anchor. Двухрежимный carrier не вводится, пока единый carrier воспроизводим на
чистом named commit. RAG:— (repo-grounded: audit F6 и carrier lines 66–96).

## Evidence correction contract

1. Product commit содержит только `docker/Makefile`, `docker/tests/make-contract.sh` и
   `docs/03_Dev/commands.md`: aliases исчезают из `.PHONY`, help, rules, positive test и living docs;
   negative harness доказывает, что `legacy:*` больше не резолвятся.
2. В clean detached worktree нового product commit полный P1.6 Validation запускается exact
   `bash docker/tests/p1-release-contract.sh` и обязан дать exit 0; carrier сам fail-closed резолвит
   единственный Swissknifeman marketplace root. Static/product-only пересказ не принимается.
3. Там же `plan-lint --baseline f7f45081` публикует exact total ERROR/WARN и new count, а
   `git status --short -- <P1 product paths>` возвращает пусто. Новый anchor и counts входят в
   append-only correction notes P1.1–P1.6; старые journal bytes и audits не меняются.
4. P1.6 correction прямо отменяет narrative claim «full carrier passed on 44a334ac»: `44a334ac`
   остаётся честным product-subset anchor, а full carrier получает новый P1.7 product anchor.
5. Journal lines 90–94 и 95–99 остаются двумя физическими наборами одного logical reconciliation.
   D66 классифицирует это как immutable process deviation; retry/attempt consumers не должны считать
   первый набор отдельным product execution.

## Scope and orchestration verdict

F6–F10 образуют один последовательный hotspot: alias removal → clean full carrier → exact evidence
matrix → append-only corrections → phase close. Новых внешних фактов, frozen product decisions или
owner gates нет. Route `implementation/high · manual · full` соответствует тяжёлой Docker/Vite/plan
verification; workflow criterion не срабатывает для одного item. P0 и P2–P12 не меняются.
