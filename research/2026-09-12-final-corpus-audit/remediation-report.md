# Отчёт о ремедиации финального аудита корпуса 2025

Дата: 2026-09-12. Основание:
`research/2026-09-12-final-corpus-audit.md` и
`research/2026-09-12-final-corpus-audit/sol-handoff.md`.

## Итог

Локальная ремедиация C01–C15 и D01 завершена. Два ранее падавших тестовых контракта
восстановлены. Финальный полный no-TIA suite, расширенный профиль, PHPStan, Pint по всем
изменённым PHP-файлам и `git diff --check` проходят.

Зафиксирована продуктовая семантика архива: корпус состоит из завершённых закупок за
`2025-01-01..2025-12-31`, обходится по дате по возрастанию. `from`, `to`, lane и typed
filter snapshot сохраняются один раз в execution context и используются всеми
continuation/recovery jobs, даже если профиль позже изменён. После успешного первого
полного snapshot завершённая историческая выдача периодически не переснимается; новый
requested window возможен только через явный restart, который очищает старый context.

Это результат локальной реализации и тестирования, а не разрешение production rollout:
production deploy/migration, массовый crawl, live ЕИС, платные LLM и длительный soak не
запускались.

## Матрица закрытия

| ID | Реализация | Регрессионное доказательство | Статус |
|---|---|---|---|
| C01 | Добавлены все 12 партиций `prc_events` за 2025 и forward-only снятие retention; доменные события сделаны бессрочными. Историческая дата не подменяется текущей. | Запись событий в месяцы 2025, повторный detail fetch и настоящий partman maintenance с сохранением строк. | Закрыто локально |
| C02 | Archive scanner checkpoint'ится по wall-clock budget, сохраняет cursor и ставит continuation. Stale recovery возобновляет только автоматически прерванные runs. Execution context неизменно хранит mode/lane/from/to/filter; runtime crawler клонируется с исходным typed filter. Restart сбрасывает context. | End-to-end job: первый slice фиксирует completed-2025 filter; профиль и аргументы следующего job меняются; второй request и ingest продолжают исходное окно/filter/lane и завершают run. Проверены потеря dispatch, stale recovery, единственный owner и operator pause. | Закрыто локально |
| C03 | `OperatorDebt` переводит run в наблюдаемую паузу без self-dispatch/busy loop; после изменения лимита доступен явный resume. | Композиционная regression scanner → processor → job проверяет отсутствие следующего job и продолжение с тем же cursor. | Закрыто локально |
| C04 | В detail введён общий deadline; HTTP quota списывается на каждую фактическую попытку. Failed callback worker'а durable и идемпотентно учитывает попытку, backoff/exhaustion конечны. | Локальный transport и worker/recovery regressions проверяют timeout, ровно один increment, backoff/permanent failure и сетевые retries. | Закрыто локально |
| C05 | Технически невалидный supplier response остаётся retryable и не ставит `detail_fetched_at`; легитимные absent/not-applicable остаются допустимыми. Completed/Unsuccessful не добавлены в periodic rescan: после первого полного snapshot история не переснимается. | Первый supplier response ошибочен, второй полный и автоматически достижим без revision; отдельный тест законного отсутствия. | Закрыто локально |
| C06 | Screening disposition и repository writes используют атомарный fenced update по текущему lease token; stale owner не получает побочные эффекты. | Штатный Pest scenario для primary и shadow заменяет token в БД под lock и проверяет отсутствие изменений analysis/document/event/run и extraction dispatch. | Закрыто локально |
| C07 | Успешная extraction finalization и переход документа выполняются в одной DB transaction; reconciler чинит ранее stranded rows. | Fault injection на update документа откатывает status/`finished_at`; retry сходится к Succeeded/Extracted без дублирования estimates. | Закрыто локально |
| C08 | Исчерпание последней lease атомарно завершает Analysis и AnalysisRun с permanent failure и диагностикой. Старый owner не оживает, новый operator run разрешён. | Один regression параметризован для Screening и Extraction, включая запуск нового manual run после терминализации. | Закрыто локально |
| C09 | Deadline exhaustion выделен в transient `PipelineDeadlineExceeded` и больше не классифицируется как unreadable/unprocessable PDF. | Валидный PDF при коротком budget остаётся Prepared/retryable; corrupt/password outcomes не ослаблены. | Закрыто локально |
| C10 | Абсолютный deadline передаётся в slice/upload/poll/generate и ограничивает transport timeout остатком; checkpoint оставляет reserve. `SupportsFileUpload` обновлён во всех реализациях. | Slow `FakeLlmProvider` расходует/наблюдает deadline без платного LLM; проверены upload deadline, request timeout и extraction window continuation seam. | Закрыто локально |
| C11 | Отсутствующий per-entry Packed Size в solid 7z больше не превращается в ноль; ratio считается по доступным entry/archive данным с сохранением actual byte limits. | Настоящие multi-file solid 7z, non-solid 7z и ZIP создаются во временном workspace и проходят parser/validator/extraction; при отсутствии `7z` тест явно skip (в Docker binary есть). | Закрыто локально |
| C12 | Весь GSFX listing валидируется до extract/read. Для core XML введён отдельный 16 MiB declared/actual bound, bounded read и всегда изолированный parser subprocess с 256 MiB memory limit/timeout. | Oversized decoded `Data.xml` отклоняется до parser; штатный parse проходит через memory-capped subprocess. | Закрыто локально |
| C13 | `XlsxResourceGuard` до PhpSpreadsheet ограничивает entries, aggregate decoded bytes/ratio и streaming-считает XML cell nodes через XMLReader по `zip://`. | Wide/compressed XLSX и превышение cells дают контролируемый отказ; adversarial `<c>` на границе 8191 байт исключает chunk-boundary gap. | Закрыто локально |
| C14 | Download и representation publish идут через уникальный staging object, hash verification и repair missing/corrupt canonical без delete; concurrent winner принимается только после проверки hash. | False write, exception, corrupt existing object и concurrent winner; ровно одна version/event, staging leftovers отсутствуют. | Закрыто локально |
| C15 | Временные файлы и каталоги переведены в общий managed workspace; scheduled sweeper удаляет stale trees только старше безопасного lease-age. | Stale file/directory удаляются, fresh active directory сохраняется; archive/GSFX/converter/slicer paths покрыты. | Закрыто локально |
| D01 | Runbook согласован с scheduler и async download chain; ручная команда принимает `<procurement_id>` — именно числовой `prc_procurements.id`. | Документация сверена с route/command signature. | Закрыто |

## Восстановленные тестовые контракты

- `BudgetExhaustionTest`: fixture использует разрешённый source host и действительно
  достигает quota/deadline ветки, не падая раньше на target policy.
- `FetchProcurementDetailJobTest`: ожидает актуальную асинхронную цепочку
  Discovered → `DownloadDocumentJob`, а не устаревшую inline Downloaded/prepare цепочку.

Дополнительно устранены order-dependent дефекты тестов: `RecoveryScopeTest` явно использует
`RefreshDatabase`, а `DetailParseOutcomeTest` предоставляет валидные common/documents HTML.

## Проверки

Перед DB-прогонами выполнялся безопасный preflight:

```bash
make test-preflight
```

Результат: exit 0, `Test database ready: agelto_test`. Единственная применённая migration
была локально применена к `agelto_test`; production/dev БД не изменялись:

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan migrate --force
```

Результат: exit 0, `2026_09_12_100095_preserve_2025_procurement_events` — DONE.

Расширенный профиль аудита после всех продуктовых и регрессионных изменений:

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/Crawler tests/Feature/Document tests/Feature/Procurement/Pipeline tests/Feature/Procurement/Prepare tests/Feature/Procurement/Screening tests/Feature/Procurement/Analyzers/Estimate tests/Unit/Crawler tests/Unit/Procurement tests/Unit/Config/QueueTimeoutInvariantTest.php tests/Unit/Support
```

Результат: exit 0, **734 passed / 3171 assertions / 56.09 s**.

Отдельный финальный набор C06/C07/C08 (fencing, transaction fault injection,
screening/extraction lease exhaustion): exit 0, **35 passed / 177 assertions / 3.42 s**.

Полный проектный suite без TIA:

```bash
make test-preflight && docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia
```

Результат: exit 0, **1720 passed / 7833 assertions / 115.18 s**. Предыдущий полный прогон
нашёл четыре тестовых contract/order ожидания (1712 passed, 4 failed); после их исправления
полный suite повторён целиком и стал зелёным.

Статический анализ:

```bash
docker compose -p agelto_dev exec -T app vendor/bin/phpstan analyse --no-progress --error-format=table --memory-limit=1G
```

Результат: exit 0, `[OK] No errors`.

Форматирование проверено по объединению tracked changed и новых PHP-файлов:

```bash
changed_php_files=$({ git diff --name-only --diff-filter=ACMR -- '*.php'; git ls-files --others --exclude-standard -- app database tests | rg '\.php$'; } | sort -u)
docker compose -p agelto_dev exec -T app vendor/bin/pint --test $changed_php_files
```

Результат: exit 0, **PASS / 99 files**.

```bash
git diff --check
```

Результат: exit 0, ошибок whitespace нет.

## Остаточные ограничения и rollout/soak gate

Локальные fake/isolated tests не доказывают 24/7 throughput, production RSS, поведение
реального ЕИС/CDN или provider latency. До полного запуска нужны отдельное разрешение и
контролируемый rollout:

1. Сделать backup и staged production migration; проверить наличие всех 12 партиций 2025,
   конфигурацию pg_partman и сохранность событий после maintenance.
2. Запустить ограниченную выборку каждого месяца 2025, всех целевых завершённых статусов и
   каждого формата, включая solid/non-solid archive, GSFX и wide/compressed XLSX.
3. В soak принудительно перезапустить worker между slices и доказать continuation исходных
   from/to/filter, единственного owner и отсутствие auto-resume после operator pause.
4. Наблюдать queue age, retries/permanent failures, HTTP request quota, RSS, disk/inodes,
   managed temp workspace и storage hash mismatch/repair.
5. Сверить полноту по месяцам и дате по возрастанию, supplier outcomes, сохранность событий,
   cursor progression и ETA всего фиксированного годового корпуса.
6. Зафиксировать production target, окно soak и численные пороги допуска. Они в этой задаче
   не предоставлены, поэтому production GO не объявляется.
