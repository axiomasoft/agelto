---
audience: shared
type: explanation
status: living
---

# Краулинг и обнаружение

Acquisition находит закупки, получает согласованные снимки карточек и передаёт ссылки
на документы независимому документному процессу. Исполнением listing, detail и status
refresh владеет Process: PostgreSQL хранит намерения, работу, попытки и результаты;
очередь доставляет только `ExecuteWorkJob`.

## Профиль и запуск

Площадка объявляет реестры, identities, фильтры и capabilities через типизированный
`PlatformDefinition`. Запись площадки в БД без definition не разрешает обход.
Отсутствующая capability или неизвестный код фильтра дают явный отказ; порядок
подключения — [platform-extension.md](../dev/platform-extension.md).

`Crawler` остаётся профилем: реестр, фильтр, `listing_kind` (fresh/archive/delta),
маршрут, расписание, активность и `profile_version`. Редактирование увеличивает версию.
Площадочный preparer разрешает справочные значения и строит immutable
filter/registry/access-policy/adapter snapshot до транзакции выделения работы.
Для ЕИС коды ОКПД2 разрешаются в ID её каталога; неизвестный код запрещает запуск,
чтобы фильтр не расширился незаметно.

`ProcessRun` хранит намерение запуска. `prc_acquisition_schedules` содержит authority
по `(profile_id, acquisition_kind)` с cron/timezone/version/next_due.
Повтор тройки `(profile_id, acquisition_kind, scheduled UTC instant)` создаёт один
run; archive и delta одного профиля в одну минуту остаются разными запусками.
Run/work/required links и продвижение due записываются в одной транзакции.
Пропущенные минуты не создают неограниченную очередь: durable coverage определяет
диапазон восстановления после простоя.

Ручной запуск: `php artisan process:run <profile> --kind=fresh|archive|delta`.
Повтор той же команды сохраняет `--command-id=<UUID>`; диапазон задаётся через
`--from`/`--to`. Команда выделяет работу, а не исполняет HTTP синхронно.
Изменённое намерение с прежним UUID отклоняется. Планировщик вызывает
`process:schedule-acquisition` каждую минуту. Подробности —
[acquisition-scheduler.md](../dev/runbooks/acquisition-scheduler.md).

## Листинг и покрытие

Fresh, archive и delta используют один механизм с разными horizons/watermarks.
Fresh читает публикации и сохраняет обязательства повторного покрытия; delta читает
обновления от durable watermark с перекрытием. Отдельный backlog-rescan удалён:
незакрытые окна остаются durable coverage debt. Снимок фильтра запущенного обхода
не меняется вслед за редактированием профиля.

ЕИС использует дневные partitions и inclusive rows с 1: для блока 500 запросы
начинаются с 1, 501, 1001. Default 500 — локальная политика, bounds 1..1000.
Число 5000 не является доказательством полноты источника. Повтор страницы,
overflow или resource bound требуют price split либо explicit incomplete debt.
Ценовые интервалы перекрываются в midpoint, сохраняя дробные цены.
Профильный диапазон сохраняется; fallback ceiling не расширяет declared scope.

Initial allocation ограничен 400 source days, pass — 12 блоками, stability — тремя
проходами, split depth — 20, общий partition budget — 10000. Не размещённый хвост
остаётся для bounded continuation. Parent split не закрывает discovery до учёта
всех обязательных descendants.

CSV читается логическими записями: quoted newline и обратный слэш не склеивают
строки. Windows-1251/BOM/UTF-8 проходят bounded decoder. Неверный HTTP, HTML/challenge,
blank body, заголовок, quoting, ширина строки или обязательная identity/date не
означают пустой день. Корректный header-only CSV — empty proof.
Счётчик записей до dedup не позволяет принять повреждённый блок за короткий terminal.

`ListingFinalizer` сохраняет summaries, detail intents, page receipt, successors,
required links, transition и outbox атомарно под Process fence. Ошибка обязательной
записи откатывает весь блок. HTTP и raw artifacts подготавливаются вне locks.
Потерявший ownership worker не продвигает cursor и не публикует результат.
В полном digest остаются все наблюдения, включая дубли; canonical publication берёт
первое содержимое каждой identity и учитывает registry denylist до создания detail intents.
Последний bounded decoded CSV сохраняется по content-addressed пути под physical request ID.
Успешный HTML сохраняется по `raw_html_retention_enabled` на отдельном configured disk,
с optional gzip. Item rejection всегда сохраняет assessed bytes с совпадающим hash,
даже при выключенном retention успешного HTML. Отказ storage — dependency failure.
Raw backfill из старых Fetch удалён: каталог обновляется при записи immutable evidence.
Типизированные `ListingFacts` сохраняют способ закупки, сроки заявок и имя заказчика.
Отсутствующая колонка сохраняет прежнее поле, явно пустая может очистить его;
некорректная непустая дата не считается подтверждённой пустотой.
Площадочная metadata передаёт точный набор наблюдённых непустых полей: обновление ИКЗ
из listing сохраняет опубликованные common-info поля и их revision snapshot.
Result codec listing имеет version 3; checkpoint остаётся version 1. Calendar dates
не сдвигаются, а суточная точность обновления хранит обе границы суток источника в UTC,
включая сутки длиной 23 или 25 часов при DST.

Закрытое окно требует двух последовательных полных passes с одинаковым digest
identity и revision/content. Прерванный offset pass повторяется с начала bounded
partition. После трёх несовпадающих passes остаётся `unstable_source` debt.
День на точной settle boundary ещё open: observed coverage требует последующего
closing reconciliation, даже если fresh overlap уже ушёл дальше.
Verified watermark не перескакивает gap. Offset API не даёт атомарного снимка
меняющегося источника; полнота относится только к declared filter/price scope.

## Идентичность и история

Одна закупка принадлежит одной площадке. Primary identity — точный
`(platform_id, source_identity_type, source_identity_value)`, объявленный реестром.
Ведущие нули, регистр, значимые пробелы и Unicode representation сохраняются.
UNIQUE tuple и composite registry/platform FK запрещают смешение реестров и площадок.
Cross-platform merge и lookup по secondary identifiers отсутствуют.

Перед lookup/create finalizer берёт sorted advisory identity locks и subject rows
до work lock. `INSERT ON CONFLICT` и exact locked reread обрабатывают конкурентное
обнаружение без второго fetch. Идентичный снимок не создаёт лишнюю ревизию.
Ревизия хранит `process_run_id` для provenance. Завершение acquisition run не
разрешает удалять downstream историю или referenced raw artifacts.

Общие DTO не содержат ИКЗ, ETP number или registry GUID. Они принадлежат
`EisDetailExtension`; `EisExtensionPersister` — единственный application writer
`prc_eis_details` внутри той же fenced publication transaction. Его ошибка
откатывает canonical revision/events и cursor. UI читает эти поля через registered
extension; primary value отображается нейтрально.

## Карточка, стороны и циклы обновления

`DetailHandler` сохраняет полные независимые части в immutable artifacts и fenced
checkpoint. Повтор получает недостающие части той же revision/adapter/part.
Partial/Unavailable не публикуют смешанную карточку и не удаляют прежние стороны,
документы или результаты поставщика. CompleteEmpty разрешает сверку только полной
секции. Для ЕИС organizations разбираются из common-info без пятого HTTP.

В конце читается контрольная часть; несовпадение начинает новый bounded pass
(максимум три). Revisionless snapshot имеет evidence `bounded_revalidated`,
не доказательство атомарного снимка. Tab-only mutation и ABA могут остаться
незаметными; per-part hashes/fetched_at сохраняются.
Время опубликованной карточки UI получает из `prc_detail_snapshots`.
Legacy detail execution-поля удалены; попытки и ожидания принадлежат Process.

Организации связываются по `(platformCode, type, value)`. Роль размещающей организации
не подменяет заказчика. Для отдельного заказчика имя и ИКЗ берутся из его блока;
контакты другой организации не копируются. Неизвестная роль, отсутствующий identifier
или противоречие запрещают destructive reconciliation сторон. Совместная закупка
сохраняет каждого заказчика; `customer_org_id` заполняется только при единственном.
Сверка одной секции сохраняет роли остальных. Позиции сверяются с «Итого N»;
раскрытые `truInfo_*` строки не считаются отдельными позициями.

`RefreshRepository` под lock procurement выделяет durable status/detail cycle и
двигает next_due атомарно. Неизменившийся status завершает собственный цикл;
следующий due создаёт новую работу. Стартовая cadence: активные — 6 часов, недавно
закрытые — 24 часа в течение 30 дней, старые terminal — 7 дней; полная detail
revalidation — 24 часа. Последняя обнаруживает поздние документы и результаты
при неизменном common-info. Request-layer status cap остаётся отдельным ограничением.

Статусы не монотонны. Старый ответ к изменившемуся head сохраняется history-only
и назначает reconciliation. Mapping: exact registry → platform default → unknown.
Новый raw status создаёт один incident на platform/registry/hash без item failure.
Remap публикует обычную revision/status event, без bulk UPDATE в обход истории.
`succeeded_at` сохраняет доменный положительный исход (awarded/completed или
победитель), а не успешность HTTP/разбора; событие «закупка состоялась» однократное.

## Передача документов

Полная карточка атомарно создаёт `DocumentReference`, procurement link и pending
download intent на существующем `prc_documents`. Ключ — platform/source key с
adapter scope и версией, не signed URL. Ротация ссылки увеличивает
`access_descriptor_version`, сохраняя identity и content head. Повтор того же
descriptor не увеличивает версию. Observation time и metadata сохраняются вместе.

До документного cutover intent имеет `pending / handler_not_activated`, а
`source_url` остаётся NULL: старый downloader не подбирает эту работу.
Acquisition не диспатчит document/AI jobs. Сверка удалённой ссылки не удаляет
immutable bytes, общий документ или работу другого run. История bytes и последующая
обработка принадлежат [документному процессу](document-and-estimate-pipeline.md).

## Завершение и управление

Пока discovery открыт, отсутствие работы не означает completed. Producer закрывает
его после terminal proof всех branches либо explicit incomplete reason.
Detail/status имеют `required` даже при shared execution; document provenance
имеет `observes` и не удерживает acquisition run. Executed count относится к creator,
а progress учитывает required work каждого заинтересованного run.

CancelRun отменяет намерение и сохраняет shared work другого run; hard cancel work —
отдельная команда. Новые required links не добавляются к cancelled run.
Superseded required links заменяются successor атомарно. Incomplete discovery после
drain остаётся completed_with_issues.

Process UI и typed audited actions управляют pause/resume/cancel/validate/retry
с RBAC и expected version. Pause запрещает новые grants; разрешённый in-flight
запрос может закончиться. Resume сохраняет health/adapter hold и историю.
Пауза оператора не считается stalled. Circuits resource/table и crawler runs
удалены вместе с consumers; legacy incidents navigation/read table сохраняются
до P3.17. Process projections показывают новые инциденты и причины ожидания.

OutboundExecutor владеет exact route profile/default/explicit direct, TLS trust,
budget/deadline и owner/control gate перед каждым send. Недоступный профиль не
разрешает direct fallback. Retry/redirect записываются отдельными physical attempts.
Сетевой отказ даёт durable defer/blocked; только genuine rejected input расходует
лимит 3 с due 30/120 секунд. Code fault имеет отдельный handler budget.
Recovery/outbox восстанавливают работу после потери worker/Redis; истёкший lease
не разрешает новый HTTP.

Проверки и два clean install выполняются в [isolated rig](../dev/runbooks/process-test-rig.md).
Адресные tests не заменяют приёмку P2.18 и полную проверку группы P2.19.
Документация не разрешает production deployment, live crawling или paid AI.
