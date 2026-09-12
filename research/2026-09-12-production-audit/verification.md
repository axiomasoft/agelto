# Проверка аудита 2026-09-12

Baseline: `941ade6d743628acca72564cd31afabd1bb9bd26`, изначально чистый worktree.
Docker project: `agelto_dev`; PHP runs через app; PostgreSQL: `agelto_test`.
Это журнал команд и наблюдённых результатов, не сырой автоматически сохранённый stdout.

## Выполненные команды

```bash
make test-preflight
```

Exit 0: `Test database ready: agelto_test`. Прочитаны `tests/bootstrap.php`,
`phpunit.xml`-контракт через preflight, `tests/Pest.php`: forced testing DB,
RefreshDatabase, test filesystem isolation. Dev/prod DB для fault probes не использовались.

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/ProductionAuditProbeTest.php
```

Итоговая версия: exit 0, **7 passed / 19 assertions**, 0.98 s.
Query-count output: `AUDIT recovery limit=1 documents=20 queries=61`.
Шесть tests характеризуют плохое поведение; седьмой опровергает первоначальную
гипотезу о misclassification отсутствующего storage object (получен UnableToReadFile).

История проб: первый набор 5 tests — 5/15 GREEN. Затем две новые пробы дали 2 failures:
страница без registry number правильно отклонялась, missing storage правильно бросал
исключение. Первую уточнили до неполной страницы с номером (проходит guard, теряется due);
вторую сохранили как отрицательный контроль. Продуктовый код не менялся ради проверок.

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/Document tests/Feature/Procurement/Pipeline tests/Feature/Procurement/Prepare tests/Feature/Crawler/DetailAnalysisProcessorTest.php tests/Unit/Crawler/DocumentsParserTest.php tests/Unit/Procurement/Prepare/ArchiveEntryValidatorTest.php
```

Exit 0, **224 passed / 859 assertions**, 16.22 s. Это расширенная выборка, не полный suite.
В неё не входит временный ProductionAuditProbeTest.php: аудит и существующие регрессии
проверены отдельно.

```bash
docker compose -p agelto_dev exec -T app vendor/bin/phpstan analyse --no-progress --error-format=table
docker compose -p agelto_dev exec -T app composer audit --locked --no-interaction
```

Оба exit 0: `[OK] No errors`; `No security vulnerability advisories found.`
Composer проверял актуальный доступный advisory response для lock на момент запуска,
не внешние конвертеры/OS packages. PHPStan использовал существующую конфигурацию и cache.

Также выполнены read-only поиск security sinks, чтение job/queue/storage/recovery цепочек,
проверка установленного Laravel Worker timeout handler, `git status`, `git check-ignore`,
`codex --help`. Пробы использовали Http/Queue/Storage/Sleep fakes; LLM не вызывался.

Финальная проверка артефактов: `git diff --check` exit 0; `php -l` архивированной
пробы — No syntax errors; проверены существование file:line anchors и относительных
ссылок, 10 F-разделов и отсутствие временной копии в tests/Feature. Рабочее дерево
содержит только новые research-артефакты этого аудита; продуктовый код не изменён.

## Повтор воспроизводящих проб

Временный файл после прогона перемещён в research, чтобы не добавлять в штатный suite
тесты, ожидающие дефект. При повторе сначала убедиться, что tests/Feature/ProductionAuditProbeTest.php
не существует и целевой путь не ignored. Следующие команды не перезаписывают существующий файл:

```bash
test ! -e tests/Feature/ProductionAuditProbeTest.php && cp research/2026-09-12-production-audit/ProductionAuditProbeTest.php tests/Feature/ProductionAuditProbeTest.php
make test-preflight
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/ProductionAuditProbeTest.php
```

После прогона удалить только созданную собственную копию (сверив с research оригиналом).
Bootstrap запускает изолированный test filesystem, RefreshDatabase откатывает test data.

## Границы уверенности

- Нет новых production/live ЕИС/LLM обращений, deploy, перезапуска общего Horizon.
- Нет 72-часового soak, замеров throughput/RSS/inodes под большим боевым объёмом.
- Нет нового реального SIGKILL/SIGALRM эксперимента: F07 — трасса установленного кода.
- Lease test управляет временем и двумя model instances; это не multiprocessing test.
- SSRF probe доказывает прохождение URL к HTTP sink с fakes, не доступ к metadata service.
- Проверки не являются внешним penetration test или полной сертификацией безопасности.
- Старый plan/status/handoff не изменялись; этот отчёт — вход следующего плана, не его close.
