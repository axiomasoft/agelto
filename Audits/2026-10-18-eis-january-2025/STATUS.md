# Состояние прогона

Оркестрация ремонта завершена: основной run `cnd-20261018T114016Z-b7a66c`,
исполнитель `codex/gpt-6.1-sol/xhigh`, ревью `grok/grok-4.7/high`. Два Minor исправлены
в follow-up `cnd-20261018T123050Z-bf534e`; повторное ревью GREEN, acceptance V1–V3 exit 0.
Основной локальный commit `60d6162c`; координатор сохраняет follow-up, затем push и live rerun.
См. `evidence/orchestration-{main,followup}.json` и review/check evidence.

## Текущее состояние завершённого run3 и ремонта

Сохранённый snapshot от 2026-10-18 11:39:23 UTC подтверждает: run3 завершён
в 11:21:01 UTC, охват 20–31 января, 12 CSV HTTP 200, 2189 записей, одна новая закупка,
ноль обновлений/ошибок строк. В БД 2516 закупок и 2516 ревизий; details/documents/AI/estimates
и detail attempts =0, очереди ready/reserved/delayed =0. Это завершившийся отложенный
операторский запуск; новая delayed команда не ожидает. Idle worker остановлен координатором
перед ремонтом (SPEC); свежий live snapshot executor не выполнял.

Evidence: `evidence/snapshot-run-3-completed.json`, `evidence/raw-verification-run-3.json`,
`evidence/source-count-expanded.json`. Raw run3 покрывает только 20–31 января: 2189 уникальных
номеров, нет mismatches/missing numbers. В сочетании с run1/run2 и независимым totalCount=2516
это подтверждает накопленный январский предметный охват с ценой до 1 трлн, не все госзакупки.

В кандидатном коде исправлены EIS-001/002/003/006/007 и добавлен downstream gate EIS-004.
Новый synthetic proof — `evidence/csv-parser-proof-repaired.json`; исходный
`evidence/csv-parser-proof.json` сохранён как доказательство прежней ошибки.
EIS-008: автоматическое восстановление Failed не реализовано, причины/необходимое решение
в ISSUES. Полный review и acceptance базового ремонта завершены; базовый код сохранён
в commit `60d6162c`. Текущий follow-up закрывает только два подтверждённых Minor F1/F2
из `evidence/orchestration-review-1.json` и нормализует текстовые captures.
Проверки follow-up — только agelto_test и HTTP fixtures; его review, полные acceptance,
commit/push и январский live rerun остаются координатору. Env, live БД, очереди и proxy
health executor не менял. Перед следующим операторским запуском координатор должен применить
`CRAWLER_DOWNSTREAM_ENABLED=false` с перезагрузкой config/worker; новое значение по умолчанию true.

## Прогоны — актуальный итог

| Run | Результат | Данные | Проверка |
| --- | --- | --- | --- |
| 1 | completed, 10:28:57–10:38:54 UTC, с паузой | 2515 закупок, 31 CSV HTTP 200, 0 ошибок строк | Raw/БД совпадают; независимый totalCount нового ценового охвата =2516 |
| 2 | failed, 10:40:21–10:44:47 UTC | 19 дневных окон, 327 прежних записей, 0 новых/обновлённых | На 20 января исчерпаны 5 сетевых попыток; курсор сохранён |
| 3 | completed в 11:21:01 UTC, отложенный повтор уже исполнен | 20–31 января, 12 CSV HTTP 200, 2189 записей, 1 новая; накоплено 2516 закупок/ревизий | Snapshot 11:39:23 UTC: downstream/detail attempts =0, ready/reserved/delayed =0; raw согласован |

В snapshot остаётся один исторический failed job от run2; новый отложенный повтор не ожидает.
Накопленный результат относится к согласованному январскому охвату с ценой до 1 трлн.
Post-repair live rerun ещё не выполнен и этим результатом не доказан.

## Проверки и дальнейшие действия — актуально

- Production-код parser/mapper исправлен в базовом ремонте: synthetic proof сохраняет все
  500 multiline-записей и отклоняет HTML/пустое тело. Это локальная проверка parser/mapper,
  без доказательства живого транспорта: `evidence/csv-parser-proof-repaired.json`.
- Исторические точечные тесты и неуспешные попытки сохранены в `evidence/repair-verification.json`
  и `evidence/repair-*.txt`. Нормализация текстовых captures не является повтором этих проверок.
- F2 разделяет причины отказа fixture до provisioning/dispatch: downstream сообщает
  `CRAWLER_DOWNSTREAM_ENABLED`, screening — `AI_SCREENING_ENABLED`; оба отказа доступны UI
  через `OperationFailed`. Команды и результаты follow-up — `evidence/orchestration-followup-tests.json`.
- Автоматический recovery Failed (EIS-008) остаётся открытым решением. Обработка статусов
  delta/backlog оставлена отдельным вопросом в ISSUES; её поведение в этом follow-up не меняется.

Следующее действие координатора: проверить малый diff и acceptance, выполнить commit/push,
затем разрешённый live rerun с новым snapshot, raw verification, сравнением независимого
totalCount и проверкой ревизий/нулей downstream. До этого live-поведение исправленного
кода остаётся непроверенным; исходный завершённый run3 и его свидетельства сохраняются.

## Историческое состояние до run3 (сохранено)

Ниже — факты и инструкции на 10:49 UTC, до исполнения delayed команды и до ремонта.
Указания «следующее» и утверждения о неисправленном parser относятся только к этому моменту.
Текущее продолжение и итог run3 описаны выше.

2026-10-18, 10:49 UTC (13:49 Волгоград): полный месяц ещё НЕ добран — 2515 из 2516 закупок.
Первый проход завершён, второй остановился на сетевых таймаутах. Один отложенный повтор
поставлен на 11:15:02 UTC (14:15:02 Волгоград), после штатного cooldown прокси.

Охват: завершённые закупки ЕИС 44-ФЗ, ОКПД2 43, публикация 2025-01-01–2025-01-31,
по возрастанию. Владелец подтвердил сохранение этого охвата в ответе на уточнение.
Это не весь реестр госзакупок.

- Исходный commit: `920b59be`, ветка `local/codex-setup-2026-09-14`.
- До очистки: локальная БД agelto имеет 134 таблицы, 0 закупок и 0 прогонов.
- Backup создан: `storage/backups/before-eis-january-2025-20261018.dump` (ignored).
- Выполнены migrate:fresh, BaseSeeder, новый сид прокси и январского профиля #3.
- Прокси #1, source=profile, scopes только CrawlListing. Egress RU подтверждён через
  ProxyResolver и отдельно host Chromium. TLS боевого клиента сохранён.
- AI screening/extraction выключены в локальном env; scheduler/Horizon остановлены.
  Работает только `queue:work redis_crawl_long --queue=crawl_long` в app.
- App/postgres/redis/minio здоровы, `http://127.0.0.1:8080/up` возвращает 200.

### Прогоны на 10:49 UTC

| Run | Результат | Данные | Проверка |
| --- | --- | --- | --- |
| 1 | completed, 10:28:57–10:38:54 UTC, с паузой | 2515 закупок, 31 CSV HTTP 200, 0 ошибок строк | Raw/БД совпадают, но источник без ценового потолка даёт 2516 |
| 2 | failed, 10:40:21–10:44:47 UTC | 19 дневных окон, 327 прежних записей, 0 новых/обновлённых | На 20 января исчерпаны 5 сетевых попыток; курсор сохранён |
| следующий | один delayed command на 11:15:02 UTC | только 2025-01-20–2025-01-31, тот же профиль #3 | Продолжение через штатный crawler:run-archive; новый run создастся после dequeue |

Run #1: запрос паузы 10:33:09, paused 10:34:34 (85 секунд), курсор 2025-01-25;
resume 10:35:44, worker продолжил тот же run. Конкурирующий start во время running отклонён
CrawlerRunAlreadyActive; запрос сразу после enqueue resume дополнительно удержан unique job.

На завершение run #1: raw stream parser =2515 уникальных номеров, пропущенных raw-номеров в БД
нет. Карточек/документов/версий/AI/смет =0, повторных ревизий =0. Источник без цены =2516.

Новый профиль с ценой до 1 трлн также дал независимый SSR totalCount=2516
(`source-count-expanded.json`: два transport timeout, затем HTTP 200).
Первые 19 дней второго прогона не добавили ни одной записи/ревизии: пропущенная закупка
лежит в оставшемся диапазоне 20–31 января. В БД 2515 закупок и ровно 2515 ревизий.

### Состояние в 10:49 UTC и тогдашнее продолжение

- Proxy #1: open_count=1, global open_until=`2026-10-18 11:14:47+00`.
  Причина — пять последовательных network_timeout в CSV-запросе за 20 января.
  Health не сбрасывали, прямой канал и другой proxy не подставляли.
- В `redis_crawl_long/crawl_long`: ready=0, reserved=0, delayed=1.
  Это **одна** QueuedCommand `crawler:run-archive 3 --from=2025-01-20 --to=2025-01-31`,
  не бесконечный цикл повторов. Внутри используется штатный StartCrawlerRunAction.
- Idle worker оставлен в app, PID-файл `storage/app/eis-january-2025-worker.pid`.
  Он должен обслужить delayed команду после 11:15:02 UTC при сохранённом контейнере.
- Все очереди detail/download/prepare/AI пусты; downstream DB =0, detail attempts=0.
  `failed_jobs=1` — неуспешный архивный job #2, сохранён для диагностики.
- Не создавать ручной параллельный запуск и не делать reset, пока delayed command ожидает.
  `control.php start/resume` и повторный defer-retry отклоняют непустую архивную очередь.
- Следующая сессия: сначала свежий snapshot. Если очередь уже сработала, проверить новый run
  и числа. При 2516 закупках, 2516 ревизиях, 0 повторных ревизий, совпадении сырья и totalCount
  можно принять полноту ЭТОГО предметного охвата. Если снова failed — записать факт и cooldown;
  этот audit helper автоматически второй повтор не создаёт.

Санитизированные свидетельства: `evidence/snapshot-final.json`, `snapshot-run-2-failed.json`,
`preflight-cooldown.json`, `deferred-retry.json`, `raw-verification-run-{1,2}.json`.

### Проверки и открытые вопросы до run3 и ремонта

- 20 существующих тестов CSV/ArchiveWindowScanner прошли (67 assertions).
- 7 тестов новых сидов прошли после расширения цены (56 assertions), только agelto_test.
- Синтетически воспроизведена потеря 1 строки из 500 при multiline CSV и принятие HTML/пустого
  тела как 0 строк. Эти дефекты пока НЕ исправлены в production-коде: см. ISSUES.
- Живого срабатывания половинного деления не было: дневные блоки меньше 500 строк. Сценарии
  split/resume/operator debt проверены существующими тестами, это отдельный вид evidence.
- Browser SSR timed out за 30 секунд; независимый totalCount затем получен HTTP-клиентом
  через тот же resolver/TLS/budget. Не подменять этот факт браузерной проверкой.
- Из Redis адресно удалены 10 старых doc_prepare jobs; это не задачи текущего обхода.

Следующее: после окончания cooldown наблюдать единственный отложенный повтор, сохранить его
snapshot и raw verification, сравнить итоговые уникальные номера с totalCount=2516, проверить
ревизии и нули downstream. До этого полнота января не принимается.
