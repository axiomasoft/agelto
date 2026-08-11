# P2 audit remediation — финальный execution design

## Вход и неизменяемые границы

Источник — `phases/P2/P2.md` → `## Audit P2 — 2026-08-11` и прямое решение владельца от
2026-08-11: это последний design-прогон; далее выполняются P2.7, close и повторный audit, а
обнаруженные после этого дефекты frozen acceptance исправляются bounded fix-ами без нового
design-слоя. P0/P1 product contracts, чужие фазы, пользовательский `AGENTS.md` и private AzGuard
source/ref сверх D19 pin не меняются. RAG:— (repo-grounded: audit scope-payload и owner message).

## Сведение F1–F8 в один slice

| Находка | Обязательный результат P2.7 |
|:--|:--|
| F1 | Doctor проверяет непустые APP/DB/Redis/AWS secrets, closed vendor manifest + exact checkout/build attestation, полный обязательный BaseSeeder graph и canonical initial SuperAdmin; degraded fixtures дают nonzero production gate без утечки значений. |
| F2 | Один Bash carrier строит и при явном publish mode публикует app+Doctor pair из одного clean source SHA, ставит одинаковый immutable revision label, проверяет оба образа и делает `prod:doctor` воспроизводимым; post-close `189dea38` входит в P2.6 correction evidence. |
| F3 | Fake vendor fixture становится fail-fast; все preflight negative classes проверяют exact exits, включая vendor failure 11, на item commit и final tree. |
| F4 | Secret root/targets fail-closed на symlink/non-regular path; contract имеет exact closed keys, safe grammar и no duplicates/control/traversal; entrypoint не использует `eval`; atomic failure, Compose render и build/history leakage доказаны sentinel fixtures. |
| F5 | BaseSeeder double-run и Doctor pass/warn/fail/skip/local/prod/redaction покрыты behavioral Pest suites; carriers действительно запускают Pest/Pint/PHPStan/Task/citation/diff, а не greps. |
| F6 | P2.1/P2.2/P2.4 acceptance больше не optional/fake-only: real Composer, Docker build/final filesystem, dev/prod Compose и fresh temporary clone→healthy/prod-image branches обязательны. |
| F7 | P2.1–P2.6 получают только append-only correction events с original/post-close/P2.7 anchors и канонической строкой `write-site: pre_mutation: allow · pre_final: allow`; `.state.md` и другие projections только регенерируются. |
| F8 | Root/phase handoff ведут P2.7→close→audit; отрицание, ложно распознаваемое как декларация отдельного carrier-а, удалено из pre-audit phase body. |

RAG:— (repo-grounded: P2 audit F1–F8, current Docker/PHP/tests tree).

## Порядок внутри P2.7

1. Исправить fail-closed shell boundaries: secret parser/entrypoint и preflight negative fixture.
2. Сделать P2.1/P2.2/P2.4 carriers обязательными real gates с project-scoped temporary resources;
   никакие owner/dev volumes или БД не удаляются.
3. Реализовать полный BaseSeeder/admin и Doctor diagnostics behavioral matrix. DB feature tests
   запускаются только после проверки `APP_ENV=testing` и имени БД с `_test`; встроенный
   `TestEnvironmentGuard` остаётся второй линией отказа.
4. Вынести сложную image-pair логику из Make в Bash: clean full source SHA, exact vendor pin,
   одинаковый OCI revision label для `agelto/app:<tag>` и `agelto/doctor:<tag>`, локальный
   registry fixture для publish/pull proof. Make остаётся тонким entrypoint.
5. Один `docker/tests/p2-release-contract.sh` последовательно запускает шесть усиленных carriers,
   focused Pest, scoped Pint/PHPStan, Task views/lint, strict citations и diff checks. Любой skip,
   optional env-switch или fake-only substitute — красный результат.
6. Только на committed GREEN product anchor append-only исправить closure evidence P2.1–P2.6,
   штатно пересобрать state→bundle→decisions→status, закрыть P2.7 и передать фазу в close/audit.

## DoR verdict

Открытых design-развилок нет. Один item выбран не ради сокращения количества работ, а потому что
его acceptance строго последовательна и имеет один terminal proof. Execution route —
`implementation/high`, `manual`, `Review=full`; отдельный parallel carrier не требуется.
