# Аудит надёжности закупок и смет — 2026-09-23

База: `3acfd616`, с сохранением существующих локальных изменений владельца.
Формат работы: проверяемый аудит и исправления в текущей рабочей копии, без отдельного плана.
Исторический аудит от 12 сентября не переписывается: commit `9a2a8225` уже устранил
существенную часть прежних проблем. Ни предыдущие, ни новые локальные тесты не доказывают
отсутствие всех ошибок или готовность к безнадзорной эксплуатации.

## Итог

Закрыты 10 пунктов: 8 Major и 2 Minor. Финальный полный PHP-прогон:
**1739 passed, 2 failed, 7917 assertions, 125.48 s**. Обе оставшиеся ошибки —
`DocsIntegrityTest` для существовавшего до аудита generated-файла
`docs/dev/subagents-and-workflows.md` (frontmatter и достижимость из оглавления).
Все остальные тесты полного запуска прошли; полный suite не объявляется GREEN.
PHPStan, Rector, Pint, Composer audit и `git diff --check` прошли.

## Область

Прослеживаются listing → ingest → карточка/вложения → download → prepare → screening →
extraction → materialization, восстановление очередей, ручной re-run и журнал админки.
Отдельно проверяются недоверенные URL/архивы, полномочия и сохранность результатов.
Production, реальные запросы ЕИС и платные LLM-вызовы в проверку не входят.

## Находки и работа

| ID | Уровень | Сценарий и последствие | Исправление / проверка |
|---|---|---|---|
| A01 | Major | `EstimateStitcher::dedupAdjacentComposite` удаляет легальные соседние позиции с разными `row_index`; flat-вход не содержит доказательства overlap. | Сохранить разные индексы, регрессия с одинаковыми значениями. |
| A02 | Major | `EstimatePersister` игнорирует аномалии `StitchReport`, включая неполные строки; результат без надёжного `rows_expected` может получить `valid`. | Сохранить объяснения и статус, требующий проверки. |
| A03 | Major | `ExtractionOrchestrator::finalizeSuccess` коммитит сметы раньше проверки lease и финализации. Проснувшийся старый worker может записать устаревшие позиции; ошибка финализации оставляет частичный результат. | Единая транзакция публикации со статусовыми и lease-ограничениями; fault injection. |
| A04 | Major | `EstimatePersister::rawResultAttributes` не проверяет `Storage::put() === false`, сохраняя ссылку на несуществующий сырой результат. | Fail-closed запись и проверка storage failure. |
| A05 | Major | `RerunAnalysisAction` выполняет supersede/start/create/status отдельными записями без транзакции и проверки актуальности. Сбой или повторное нажатие оставляет повреждённый lifecycle. | Атомарный re-run, блокировки, запрет старой версии/активной обработки, регрессии. |
| A06 | Major | Recovery extraction требует screening того же run, тогда как ручной extraction re-run создаёт новый run без screening. Потерянный dispatch или checkpoint такого re-run не восстанавливается. | Подбор допустимого ручного extraction по его durable state. |
| A07 | Major | `CsvBlockIngestor` поглощает ошибки строк и возвращает успешный `FetchResult`; fresh/archive могут продвинуть курсор поверх непрочитанных закупок. | Сохранить evidence и завершить блок ошибкой до продвижения курсора. |
| A08 | Minor | В «Журнале процессов» отсутствует вывод `context.error`; оператор видит одинаковое общее сообщение без причины сбоя. | Отобразить причину и идентификатор, проверить доступ и экранирование. |
| A09 | Minor | Имена archive entry передаются 7z/unar как аргументы. Прежний path guard пропускал `-…`, `@…`, wildcard и управляющие символы, имеющие специальный смысл для CLI/metadata. Подтверждено по вызовам и локальному `7z -h` / `unar -h`; исполнение shell-команд не утверждается. | Отклонение неоднозначных имён, `7z -spd --`, hostile-path и реальные extraction tests. |
| A10 | Major | Re-run screening оставлял старый активный extraction; новый job мог принять его terminal-статус за завершённую работу. После цепочки success → failed re-run → success persister удалял только сметы непосредственного родителя, оставляя дубликаты первого успешного анализа. | Инвалидация downstream при re-screening, lineage нового extraction, атомарная замена superseded-смет той же версии. |

Исправления внесены по всем A01–A10; факты проверок ниже. A08 включает явные read-only
права встроенного оператора и запись ошибок строк листинга в журнал с ID прогона.

## Поведенческие доказательства

- До изменений основной выборки: **402 passed / 1840 assertions**.
- Новые требования к A01/A02/A04/A07 сначала дали **4 failed / 25 passed**. После исправлений
  тот же набор прошёл в составе расширенных запусков. Tests ожидают правильное поведение,
  это не characterization-тесты, которые считаются зелёными при наличии дефекта.
- A03: подмена lease после последнего LLM-ответа не публикует смету и не снимает lease
  нового владельца. PostgreSQL trigger, отклоняющий финализацию документа, откатывает также
  сметы и analysis; повтор сходится к одному результату.
- A05: PostgreSQL trigger на создание rerun откатывает supersede/run/status; повторное
  нажатие по старому объекту не создаёт ещё один run.
- A06: после намеренного удаления dispatch из fake queue reconciler доставляет ручной
  extraction. До исправления новый тест возвращал 0 dispatch вместо 1.
- A07: ошибка маппера оставляет failed run с прежним cursor, сохранённый raw artifact,
  `rows_errors=1` и понятную оператору запись с причиной и ID прогона.
- A08/A09: **28 passed / 48 assertions** в тестах журнала и archive validator/extractor,
  включая настоящий ZIP и solid/non-solid 7z. UI проверен через HTTP/Livewire, без браузерного
  визуального прогона; неизвестный HTML из ошибки экранируется.
- A10: обе регрессии сначала упали (старый extraction не superseded; две сметы вместо одной).
  Затем исправлены владелец состояния и замена предыдущих результатов.

## Основные места изменений

- A01/A02: `app/Services/Procurement/Analyzers/Estimate/{EstimateStitcher,StitchReport,EstimatePersister}.php`.
- A03: `app/Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php:775`.
- A04: `app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:407`.
- A05/A10: `app/Services/Procurement/Extraction/RerunAnalysisAction.php:50`,
  `app/Repositories/Procurement/Ai/AnalysisStoreRepository.php:77`,
  `app/Repositories/Procurement/Analyzers/Estimate/EstimateStoreRepository.php:43`.
- A06: `app/Services/Procurement/PipelineRecoveryReconciler.php:98` и `:215`.
- A07: `app/Services/Procurement/CsvBlockIngestor.php:146`, `OperationLogWriter.php`.
- A08: `app/Filament/Resources/Procurement/OperationLogs/Tables/OperationLogsTable.php`,
  `app/Guards/Admin/Permissions/OperationLogPermission.php`, `app/Guards/Admin/Roles/OperatorRole.php`.
- A09: `app/Services/Procurement/Prepare/Archive/{ArchiveEntryPath,ArchiveExtractor}.php`.

## Что проверка не закрывает

Это аудит кода и локальных отказов, а не свидетельство безошибочного production.
Не выполнялись 72-часовой нагрузочный прогон, восстановление production backup, проверка
реальных лимитов/доступности ЕИС и измерение качества модели на боевом gold-корпусе.
Существующая арифметическая сверка итогов остаётся best-effort: первая подходящая строка
footer по `kind`/названию не является гарантией выбора юридически/сметно правильного итога.
Неполные строки теперь видны как требующие проверки, но полностью автоматически не восстановлены.

Широкий прогон обнаружил две независимые проблемы в уже существующем незакоммиченном
`docs/dev/subagents-and-workflows.md`: нет frontmatter и ссылки из оглавления. Это generated-core
файл владельца; его содержимое не переписывалось и docs guards не ослаблялись. Исправлять нужно
через его генератор/поддерживаемый extension-point. Точные финальные результаты — ниже.

## Окружение и ограничения

`make test-preflight` подтвердил `agelto_test` и применённые миграции. Tests/bootstrap и
TestEnvironmentGuard принудительно изолируют БД и filesystem. Для запуска локальных app,
postgres, redis, minio потребовались случайные host-порты (`APP_PORT=0 PGSQL_PORT=0`):
обычные 8080/55432 заняты другими проектами. Horizon и scheduler не запускались. После проверки запущенные для неё app/postgres/redis/minio
остановлены; volumes и данные dev-БД сохранены.


## Команды верификации

Рабочий каталог — корень вложенного репозитория `agelto/`.

```bash
make test-preflight
docker compose -p agelto_dev exec -T app php artisan test --compact --no-tia
docker compose -p agelto_dev exec -T app vendor/bin/phpstan analyse --no-progress --memory-limit=2G
docker compose -p agelto_dev exec -T app composer audit --locked --no-interaction
git diff --check
```

Rector (`process --dry-run --no-progress-bar`) и Pint выполнены на 23 изменённых в этом
аудите PHP-файлах, включая новые файлы; чужие незакоммиченные правки не форматировались.
Итог Rector — `OK`, Pint — `PASS 23 files`, PHPStan по всему проекту — `No errors`,
Composer audit по lock-файлу — `No security vulnerability advisories found`.
`make quality` целиком не запускался: его исторический wrapper обращается к Compose без
`-p agelto_dev`; проверки выполнялись явно в правильном проекте.

## Применение результата

Изменения оставлены в рабочем дереве, без commit/deploy и без запуска scheduler/Horizon.
Миграций нет. При штатном rollout обновить встроенные роли (`guard:sync-roles`).
Исправления не пересчитывают существующие сметы и не меняют завершённые прогоны задним числом:
документы, затронутые потерей позиций или неполным извлечением, нужно повторно разобрать
после применения изменений. Старые результаты сохраняются до успешной публикации замены.
