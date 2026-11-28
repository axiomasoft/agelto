# Исполнение независимого аудита — 2026-10-08

Исходная main: `75422c6f`. Основание: [аудит](2026-10-08-audit-fixes-1-review.md).
Изменения R1–R12 реализованы. CI не используется; локальные итоговые проверки перечислены ниже.

| Рекомендация | Реализация и доказательство |
|---|---|
| R1 | Registry-resolved mapper/schema, DetailParser, метод карточки, baseline, pagination. Две несовместимые синтетические площадки в одном контейнере; explicit skip отделён от malformed. |
| R2 | Один deadline HTTP/redirect/retry/body/storage, гарантированное закрытие ответа и доменный backoff. Проверки 503, redirect, медленного потока и отсутствия DocumentVersion при отказе. |
| R3 | Два совпадающих прохода ограниченного date/price окна; три нестабильных — пауза. Неполное окно после сбоя перечитывается с начала, delta overlap включён. Карантин не вводился: ошибок с разрешением продвинуть cursor нет. |
| R4 | Round-robin по площадкам для деталей; 10 dispatch на площадку за тик, durable claim на 11 минут, счётчик фактически принятых unique jobs. Проверены плохие contexts, настоящая недоступность Redis и независимый общий HTTP budget между процессами. |
| R5 | 8 МиБ decoded body, 1000 CSV rows, поле 256 КиБ, 256 колонок, ограничение разделителей и deadline. Storage вынесен из run lock; лимиты сохраняют cursor. |
| R6 | Обязательный documents container из имеющегося реального fixture, один baseline snapshot на карточку, диагностика min_matches. Пустая карточка и maintenance — синтетические негативные fixtures; нового live capture не было. |
| R7 | Сохранены точность, UTC interval end, HTTP observation time с микросекундами и флаг неоднозначности. DST 23/25 часов и обратный приход наблюдений проверены. Точные одинаковые timestamps сохраняют прежнюю политику записи. |
| R8 | Новая forward migration + catalog bounds repair, частичное покрытие и чужое имя проверены. Retention fallback учитывает реальные границы. |
| R9 | Идемпотентный inspect/apply паролей; plaintext, ciphertext, чужой ключ и повреждение проверены, частичного commit нет. Пользовательская БД не изменялась. |
| R10 | Redaction журнала/контекста/ошибок, bounded retention 90/30 дней, каталог raw + backfill, progress monitor и индекс suspended runs; метка Held обобщена. |
| R11 | Fingerprint fresh-профиля, фильтра/реестра/маршрута; правка между блоками и при продолжении требует нового прогона. |
| R12 | Воспроизводимый локальный HTTP/Redis/PostgreSQL benchmark 1/2/4 workers, 503/429, slow/gzip, delayed storage, SIGKILL в трёх точках. Helper дочерних PostgreSQL-процессов сохраняет точную worker DB. |

## Нагрузочный прогон

[Машиночитаемые результаты](artifacts/2026-10-08-crawler-benchmark.json).
PHP 8.5, PostgreSQL 17, Redis 7, отдельная сеть `agelto-remediation`, test DB на tmpfs.
Каждый worker: 4 блока по 500 строк; первый вставляет, остальные подтверждают тот же контент.
У каждого блока реальные HTTP 503, 429, 200; storage write задержан на 50 мс.
В очереди Redis 4 задания на worker; очередь опустошается за указанное в JSON elapsed время.

| Workers | Строк/с | p95 блока, с | p95 queue age, с | Peak worker, МиБ | Max lock SQL, мс |
|---|---:|---:|---:|---:|---:|
| 1 | 335 | 1.66 | 4.59 | 64.5 | 1.05 |
| 2 | 723 | 1.70 | 4.37 | 64.5 | 0.52 |
| 4 | 1296 | 1.90 | 4.73 | 64.5 | 0.53 |

Lock SQL — время запросов advisory/row lock, верхняя оценка ожидания вместе с исполнением SQL,
а не отдельная статистика PostgreSQL lock wait. Набор мал (4 блока/worker), первые вставки и
повторы различаются; цифры описывают этот локальный стенд. S3 и реальные площадки не измерялись,
HTTP-вежливость не включалась в throughput. Harness разбирает CSV fixture через fgetcsv;
реальный CsvSourceMethod и его лимиты проверены отдельно в feature suite. Бюджет HTTP отдельно проверен четырьмя процессами:
при одном слоте допущен ровно один. Поэтому рабочие Horizon limits остаются по одному процессу
на crawl/detail; 4 процесса — проверенный локальный сценарий, не разрешение увеличивать нагрузку
на ЕИС. Для новой площадки начать с 1 worker и её собственного бюджета, повторить замер с её
fixture/объёмом и реальной задержкой storage перед увеличением.

SIGKILL после HTTP и внутри ingest-транзакции оставил 0 строк и cursor=1; после commit — 2 строки
и cursor=501. Во всех трёх случаях takeover/replay дал 2 строки с одной ревизией. Gzip 16 МиБ
отклонён при лимите decoded 1 МиБ, медленное тело остановлено read timeout.
EXPLAIN ANALYZE на 10 000 paused rows использовал `prc_runs_source_resume_due`: около 1.5 мс,
10 строк результата. Полный план и buffers находятся в JSON.

## Эксплуатация и границы

Команды обновления/паролей/backfill: [dev runbook](../docs/dev/crawling-pipeline.md).
Паузы и мониторинг: [операторская инструкция](../docs/operators/how-to/manage-source-pauses.md).

- Публичный offset API не предоставляет транзакционный snapshot: два совпавших прохода и
  перекрытие дат дают проверяемую стабилизацию, не математическое доказательство отсутствия
  записей, удалённых до первого запроса. Постоянно нестабильное окно не подтверждается.
- Legacy raw без surviving fetch reference не восстановить из БД: необходима инвентаризация
  object storage. Каталог и retention автоматически покрывают новые, включая pending writes.
- У старых закупок точность даты остаётся неизвестной до следующего подтверждённого наблюдения;
  миграция не выдумывает исторические времена HTTP-запросов.
- Обновление существующей пользовательской БД и live-запросы к ЕИС не выполнялись.

## Итоговые проверки

- Полный локальный Pest без TIA: **1897 passed, 1 skipped, 8467 assertions**, 105.81 с.
  Пропуск существовал до изменений (Gsfx-зависимый сценарий).
- Параллельный запуск с двумя процессами: ProcurementIngestConcurrencyTest — 3 теста / 21
  assertion; CrawlerRunExecutionLeaseTest — 4 теста / 37 assertions. Дочерние соединения
  подтверждают точную token DB; guards не ослаблялись.
- PHPStan `level: max` — без ошибок. Pint `--test` — 1645 файлов. Rector `--dry-run` по всем
  изменённым/new app-файлам — без изменений. PHP lint benchmark/HTTP server — без ошибок.
- Новые forward migrations прошли на одноразовой БД; полный suite проверил чистую установку.
  `git diff --check` — без замечаний. Исходный пользовательский worktree сохранён: статус и
  хэши всех 235 путей совпадают с исходным снимком (82 ранее удалённых файла остаются удалены).
- GitHub Actions не запускались. Коммит помечен `[skip ci]`; файлы workflows не изменены.

Команды выполнялись внутри отдельного `agelto-remediation-app` с PostgreSQL/Redis в одноразовой
сети, после настройки test filesystem и проверки `APP_ENV=testing`, `DB_DATABASE=agelto_test`.
Основной прогон: `php artisan test --compact --no-ansi --no-tia`. Точечные параллельные прогоны:
`php artisan test --parallel --processes=2 --compact tests/Feature/Procurement/ProcurementIngestConcurrencyTest.php`
и аналогично `tests/Feature/Crawler/CrawlerRunExecutionLeaseTest.php`.

При итоговом ревью дополнительно проверены: возобновление многоблочного нестабильного окна с
первой страницы, deadline последней проверки canonical storage, изменение base URL площадки,
разные historical raw disks, отсутствие ложного прогресса при HTTP 200 с невалидной схемой,
сохранение времени наблюдения именно принятой головы закупки.
