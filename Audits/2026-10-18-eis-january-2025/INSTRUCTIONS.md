# Первый этап: CSV ЕИС за январь 2025

## Задача и полномочия

Владелец 2026-10-18 поручил поднять локальный Agelto с чистой БД, миграциями и базовыми сидами,
создать сид российского прокси из локального env, выполнить архивный CSV-обход января 2025,
наблюдать надёжность и сохранять проблемы для следующих сессий. Это локальная репетиция
производственного краулера, а не доказательство готовности отдельного production-host.

Рабочий каталог: `/home/vostrikov/projects/agelto/agelto`.
Перед действиями читать этот файл, `STATUS.md` и `ISSUES.md`. Следующие этапы (карточки,
вложения, сметы и AI) требуют отдельного перехода владельца; сейчас запускается только листинг.

## Постоянные ограничения

- Период публикации включительно 2025-01-01–2025-01-31, сортировка Published ascending.
- Только российский прокси, выбираемый штатным ProxyResolver; без прямого fallback.
- Egress проверять через тот же proxy lease до запроса ЕИС. TLS — SourceTlsTrust и бандл ЕИС.
- `CRAWLER_DOWNSTREAM_ENABLED=false`, `AI_SCREENING_ENABLED=false`, `AI_EXTRACTION_ENABLED=false`.
  Downstream gate блокирует dispatch и queued jobs; после изменения config перезапустить
  только разрешённый worker. Уже исполнявшиеся downstream jobs сначала дождаться.
- Не запускать общий scheduler и Horizon: scheduler автоматически включает детализацию.
  Пока используется штатная очередь `redis_crawl_long` / `crawl_long` с отдельным worker.
- Не выводить env, Proxy::toArray(), пароли, URL с credentials и полные исключения HTTP.
- Не удалять накопленные результаты автоматически при каждой новой сессии. Сначала сохранить
  evidence и backup; reset выполнять только как явно выбранный сценарий холодного запуска.
- Недоступность источника, непроверенный ответ и пауза не означают завершение месяца.
- Полноту устанавливать по HTTP, сырым CSV, курсорам, уникальным номерам и независимому счётчику,
  а не только по статусу Completed. Открытые дефекты могут делать Completed недостоверным.

## Окружение

Compose project `agelto_dev`; файлы `.env`, `.env.docker`, `docker-compose.yml`,
`docker-compose.override.yml` (MTU 1200 для VPN). БД локальная `agelto`, PostgreSQL контейнер
`agelto_dev-postgres-1`, app `agelto_dev-app-1`. Не трогать контейнеры других проектов.

```bash
docker compose --project-name agelto_dev --env-file .env --env-file .env.docker \
  -f docker-compose.yml -f docker-compose.override.yml up -d --no-build postgres redis minio app
docker exec agelto_dev-app-1 php artisan config:clear
```

Если старые остановленные контейнеры ссылаются на удалённую сеть: тот же `up` с
`--force-recreate`, только для перечисленных сервисов, без удаления volumes.

## Продолжение

**Отложенный повтор уже завершился: run3 completed в 11:21:01 UTC, накоплено 2516 закупок
и 2516 ревизий, queues/downstream=0 (snapshot 11:39:23 UTC). Idle worker остановлен перед
ремонтом. Не считать старую delayed команду ожидающей. Следующий live rerun выполняет
координатор после review/acceptance/commit/push; reset не разрешён. Executor работает
только с fixtures и agelto_test.**

1. Проверить контейнеры, состояние worker и `snapshot.php`. Не создавать второй worker поверх
   работающего. В этой репетиции worker запущен внутри app через `docker exec -d`, он не является
   сервисом Compose и после пересоздания app требует повторного запуска.

```bash
docker ps --filter label=com.docker.compose.project=agelto_dev
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/snapshot.php
docker exec agelto_dev-app-1 sh -c 'ps -eo pid,args | grep "queue:work redis_crawl_long"'
```

2. Если предыдущий прогон paused — продолжать его, без повторного сида/миграций.
   Если running — наблюдать. `crawler:recover-stale` использовать лишь после проверки, что
   реального живого owner больше нет; сетевые повторы могут задерживать heartbeat и паузу.
   Если completed — новый `start` создаёт новый проход для проверки идемпотентности.

   Если failed и очереди пусты — обычный resume его не подберёт. Сохранить evidence и дождаться
   `proxy_health.open_until`. Затем RU preflight и новый ограниченный проход с cursor.day
   до 2025-01-31 через `crawler:run-archive`, не меняя failed на paused SQL-запросом.
   Пока source недоступен или breaker открыт, месяц не считается добранным.

```bash
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/preflight.php
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/control.php resume RUN_ID
# Либо новый обход, если нет running/paused:
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/control.php start
```

3. Worker стартует только после RU preflight, проверки отключённых AI и отсутствия чужих задач
   в архивной очереди. Остальные очереди не обслуживать. Если после сброса БД остались задачи
   старых документов — зафиксировать число и очистить только их адресной `queue:clear`,
   не трогая текущий архивный run.

```bash
docker exec -d agelto_dev-app-1 sh -c 'echo $$ > storage/app/eis-january-2025-worker.pid; exec php artisan queue:work redis_crawl_long --queue=crawl_long --timeout=3600 --tries=1 --sleep=3 --memory=512 >> storage/logs/eis-january-2025-worker.log 2>&1'
```

4. Наблюдать каждые 30–60 секунд, сохранять snapshot в `evidence/` с run ID и временем.
   JSON скрипты выводят без credentials; не пересылать необработанные application logs.

```bash
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/snapshot.php --run=RUN_ID
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/verify-raw.php RUN_ID
```

`raw_consistent` означает только совпадение сохранённого CSV с его разбором и БД. Для приёмки
месяца нужны completed, все 31 дневное окно, даты только января, совпадение уникальных номеров
с независимым totalCount ЕИС, отсутствие ошибок строк и ноль downstream. Сам `snapshot` не
является автоматическим приёмочным gate: `unavailable` в любом важном разделе нужно разобрать.

5. Независимая проверка источника; перед браузером отдельно подтверждать RU egress:

```bash
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/source-count.php
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/source-count.php --without-price
node Audits/2026-10-18-eis-january-2025/scripts/browser-check.cjs
```

HTTP-проверка использует штатный ProxyResolver, SourceTlsTrust и PlatformRequestBudget.
Браузер — независимая разведка с TLS-послаблением только в своём контексте; Chromium-кеш
должен быть установлен. `not_verified` и timeout записывать как неустановленную полноту,
а не ноль закупок. В source-count без цены проверяется, не отрезал ли архив цену >1 млрд.

6. Кооперативная остановка и ожидание `paused`:

```bash
docker exec agelto_dev-app-1 php Audits/2026-10-18-eis-january-2025/scripts/control.php pause RUN_ID
```

Пауза применяется между блоками, HTTP-попытками и секундными slices ожидания. Не перезапускать
до фактического paused; текущий HTTP может ждать до своего deadline. После paused/completed можно завершить idle worker через TERM
по сохранённому PID, предварительно сверив его команду в `ps`. Не менять статус run напрямую SQL.

Для единственного отложенного повтора failed run существует `scripts/defer-retry.php`: он берёт
последний failed profile run и его день курсора, проверяет пустую архивную очередь и выключенные
AI, ждёт через Redis delay до `open_until + 15 секунд`, затем вызывает обычный
`crawler:run-archive`. Это один запуск без самоповторяющегося цикла. Повторно запускать helper
поверх непустой очереди нельзя; он откажет. Результат предыдущего запуска —
`evidence/deferred-retry.json`.

## Исторический холодный запуск — в текущем ремонте запрещён

Следующий раздел сохранён как история первоначальной репетиции. Текущее полномочие
не разрешает reset: сохранить существующие 2516 записей.

Только для локальной БД `agelto` этого Compose project. Сначала остановить/дождаться всех его
workers и убедиться в отсутствии reserved jobs. Новый reset не нужен для продолжения paused run.
Если требуется холодный reset при ожидающей delayed команде, сначала остановить idle worker,
зафиксировать её назначение, затем адресно очистить `redis_crawl_long/crawl_long`; иначе после
сброса старая команда обратится к заново созданным ID. Общую Redis flush не использовать.
Сохранить дамп под новым именем и текущие JSON-снимки; не перезаписывать исходный backup.

```bash
umask 077
docker exec agelto_dev-postgres-1 sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > storage/backups/before-eis-next-reset.dump
docker exec agelto_dev-app-1 php artisan config:clear
docker exec agelto_dev-app-1 php artisan migrate:fresh --force --no-interaction
docker exec agelto_dev-app-1 php artisan db:seed --class=BaseSeeder --force --no-interaction
docker exec agelto_dev-app-1 php artisan db:seed --class='Database\Seeders\Procurement\EisJanuary2025AuditSeeder' --force --no-interaction
```

Для разрешённого координатору rerun в игнорируемом `.env` нужны `CRAWLER_DOWNSTREAM_ENABLED=false`,
`AI_SCREENING_ENABLED=false` и `AI_EXTRACTION_ENABLED=false`; executor env не меняет.
Не использовать `DatabaseSeeder`: в local он дополнительно создаёт Demo-данные. Сид прокси
читает только `PLAYWRIGHT_MCP_PROXY_SERVER` (HTTP URL с явным портом), хранит пароль в БД,
ограничивает scopes значением CrawlListing и привязывает узел к отдельному январскому профилю.
Наличие country=RU в БД само по себе не доказывает egress; выполнять preflight после сида.

Сид профиля сохраняет предметный охват: завершённые 44-ФЗ, ОКПД2 43, январь; цена до
1 трлн рублей вместо штатного 1 млрд (первый проход пропустил одну закупку; EIS-005).
Независимый счётчик без ценовых границ должен подтверждать достаточность этого диапазона.
Нельзя объявлять этот охват всеми госзакупками. Расширение предметного охвата — отдельное
явно записанное изменение профиля; отсутствующие ОКПД2 нельзя просто игнорировать.

## Проверки

Для PHPUnit/Pest использовать только `agelto_test` с проектным test-preflight. Живой обход —
отдельный сценарий на локальной `agelto`, он не должен запускаться из автоматических тестов.
В отчёт писать точные UTC-время, git SHA, профиль/диапазон, команды, run IDs, fetch counts,
исходы и ограничения. Секреты и сырые дампы БД хранить только в игнорируемом storage.
