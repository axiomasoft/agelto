# P10 authoring recon — quality, security and production-readiness surface

Дата снимка: 2026-08-09. Product surface совпадает с `HEAD`
`1ab8f0531241a0c127b5e8fa9b5688ed0482f56d`; dirty worktree находится в plan carriers и
`CLAUDE.md`, а не в перечисленных ниже `app/config/database/routes/resources/tests` и quality
config. Это authoring input, не результат будущего P10-аудита: P10.1 обязан пересчитать всё на
terminal P9 tree.

## Tooling baseline

- `composer.json` требует PHP `^8.4`, Laravel `^13.0`, Filament `^5.0`, Pest `^5.0` и содержит
  Pint/Larastan/PAO, но не Rector. `vendor/bin/{pint,phpstan,pest}` существуют,
  `vendor/bin/rector` отсутствует.
- `phpstan.neon` анализирует только `app` на level 5 и подключает baseline. На указанном HEAD
  `grep -c 'identifier:' phpstan-baseline.neon` даёт 54 записи, а сумма `count:` — 59 ошибок.
- `docs/03_Dev/static-analysis.md` описывает более ранний снимок с 74 baseline-записями. Документ
  сохраняет важный запрет на baseline growth, но его числа и перечень остатка нельзя считать
  актуальным evidence для P10.
- В `composer.json` нет единого `qa`/`lint`/`analyse`/`refactor` script; текущий Make carrier
  публикует test targets, но P1/P11 ещё владеют итоговым command surface и CI.

RAG:— (repo-grounded: `composer.json`, `phpstan.neon`, `phpstan-baseline.neon`,
`docs/03_Dev/static-analysis.md`, `Makefile`, `docker/Makefile`).

## Audit surface, not findings

- `app/**` содержит 643 файла и 51 203 строки PHP (`rg --files app -g '*.php' | xargs wc -l`);
  20 крупнейших файлов начинаются с `ExtractionOrchestrator.php` (775 строк),
  `EvalMetrics.php` (699) и `GsfxGoldMapper.php` (569). Размер — сигнал для P10.3, не verdict.
- `tests/**` содержит 275 файлов, `database/migrations/**` — 63 файла на снимке. Counts должны
  быть пересчитаны и привязаны к terminal P9 commit.
- Security grep нашёл raw-query patterns в 13 файлах и mass-assignment/`forceFill` patterns в
  2 файлах; Blade-XSS и unsafe-serialization/open-redirect seed patterns по использованным
  выражениям не найдены. Match не равен vulnerability: P10.2 обязан построить input→sink chain
  и disposition для каждого hit.
- Reliability seed scan видит transaction calls в 15 файлах, race/idempotency primitives в 24,
  `ShouldQueue` в 4 job files и after-commit dispatch в 11 файлах. Наличие примитива не доказывает
  атомарность, единственного retry owner или корректный state transition.
- В дереве уже есть architecture guards (`DomainLayoutTest`, `JobRetryContractGuardTest`) и
  living docs для static analysis, state machines, entity lifecycle, database и deploy. P10
  расширяет доказательства, а не создаёт параллельный канон.

RAG:— (repo-grounded: воспроизводимые `rg --files`/`rg -l` команды на указанном HEAD,
`tests/Unit/Architecture/**`, `docs/03_Dev/**`).

## Design implications

1. Baseline снимается до mutation и не подгоняется обновлением ignore/baseline.
2. Security, code/architecture debt и production-readiness получают отдельные read-only audit
   carriers; исправления не смешиваются с обнаружением.
3. P10.5 потребляет одну severity/ownership matrix и исправляет release blockers вертикальными
   red→green срезами; неизвестная архитектурная развилка эскалирует, а не расширяет item молча.
4. P10.6 закрепляет локальные deterministic gates и living docs; CI topology, Pest acceleration
   и coverage/flake policy остаются P11, clean production rehearsal — P12.

## Verification status

- Репозиторные факты пересчитываются командами, перечисленными выше.
- External compatibility Rector/PHP/Laravel намеренно не утверждается authoring recon; P10.1
  проверяет official sources на terminal dependency graph и фиксирует применимую ветку D39.
- Ни один grep-hit не назван уязвимостью или дефектом до manual verification.
