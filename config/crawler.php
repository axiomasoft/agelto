<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Filament\Platforms\Eis\EisCrawlerPlatformForm;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Platforms\Eis\Csv\EisCsvRowMapper;

return [
    'csv_max_records' => 1000,
    'csv_max_field_bytes' => 262_144,
    // CSV-only operation: gates all later dispatch and queued execution boundaries.
    'downstream_enabled' => env('CRAWLER_DOWNSTREAM_ENABLED', true),

    'platforms' => [
        'eis' => [
            'adapter_version' => '1',
            'contract_versions' => ['listing' => '1', 'detail' => '1'],
            'detail_source_method' => SourceMethod::Csv->value,
            'listing_mapper' => EisCsvRowMapper::class,
            'detail_parser' => EisDetailParser::class,
            'block_size' => 500,
            'baseline_path' => 'resources/crawler/health/eis-baseline.json',
            /*
            | Часовой пояс «наивных» дат-времени источника ЕИС (CSV-листинг, карточки).
            | Даты читаются в нём и хранятся в UTC; календарные даты без времени не сдвигаются.
            | Явная метка «МСК±N» в карточке имеет приоритет. По умолчанию — Europe/Moscow.
            */
            'source_timezone' => env('EIS_SOURCE_TIMEZONE', 'Europe/Moscow'),

            'source_methods' => [
                SourceMethod::Csv->value => CsvSourceMethod::class,
            ],
            'registry_filters' => [
                'fz44' => [
                    'filter' => EisSearchFilter::class,
                    'form' => EisCrawlerPlatformForm::class,
                    'scope_message' => 'выберите хотя бы один узел ОКПД2. Профиль без скоупа расширяет выборку на весь реестр.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Run manager
    |--------------------------------------------------------------------------
    |
    | Протухший heartbeat `running`-прогона (сек) — после этого `recoverStale()`
    | переводит его в `paused` (resumable), не `failed`.
    |
    */
    'heartbeat_timeout_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Управление источником: реакция на отказы (`SourceControlPolicy`)
    |--------------------------------------------------------------------------
    |
    | Читается и проверяется один раз при старте (`AppServiceProvider`): опечатка в виде
    | отказа, реакции или этапе — исключение `SourceControlConfigurationException` с адресом
    | ошибки, приложение не стартует. Строки в коде не ищутся — только enum.
    |
    | `http_status_kinds` — HTTP-статус → вид отказа; не указанные 5xx → `server_error_kind`,
    | прочие не-2xx → `other_status_kind`.
    |
    | `stages.<этап>.reactions` — для КАЖДОГО вида отказа (пропуск вида — ошибка конфига):
    |   LogOnly          — только лог с кодом;
    |   Record           — лог + журнал, брейкер не считает;
    |   CountTowardWait  — лог + журнал + счётчик; `failure_threshold` подряд → ожидание
    |                      `retry_interval_minutes`, затем этап сам продолжает;
    |   CountTowardHold  — лог + журнал + отдельный счётчик структуры; `layout_threshold`
    |                      подряд → остановка до оператора («Возобновить сейчас» в админке).
    | Успешный блок/карточка сбрасывает счётчики.
    |
    | `max_consecutive_skips` (int|null) — совместимое имя лимита неудачных попыток с
    | реакцией LogOnly/Record (включая ошибки отдельных строк). Курсор не продвигается.
    | При достижении лимита без успешного блока этап останавливается до оператора.
    | `null` — лимит выключен; пауза и повтор текущего блока всё равно обязательны.
    |
    | Пороги и интервал — значения по умолчанию: непустое поле строки этапа в админке
    | («Источники: состояние») перекрывает их для этой площадки; пустое — берётся отсюда.
    |
    | Пример: считать 404 отказом площадки —
    |   SourceFailureKind::NotFound->value => SourceFailureReaction::CountTowardWait,
    |
    */
    'run' => [
        'checkpoint_seconds' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Crawl stages and platform request budget (P4.1)
    |--------------------------------------------------------------------------
    |
    | The 1–4 second pauses retain the P4.7 calibration above: 194 listing blocks
    | produced 194 HTTP 200 responses and no 403/429. Retry count five is the
    | owner-approved default; the budget values are NOT calibrated and P4.4 owns
    | their enforcement.
    |
    */
    'stages' => [
        'listing' => [
            'min_pause_ms' => 1000,
            'max_pause_ms' => 4000,
            'retries' => 5,
            'retry_delay_ms' => 500,
            'timeout_seconds' => 30,
        ],
        'detail' => [
            'min_pause_ms' => 1000,
            'max_pause_ms' => 4000,
            'retries' => 5,
            'retry_delay_ms' => 500,
            'timeout_seconds' => 30,
            // NOT calibrated: matches the already-proven document pipeline batch size.
            'dispatch_batch_limit' => 100,
        ],
        'document' => [
            'min_pause_ms' => 1000,
            'max_pause_ms' => 4000,
            'retries' => 5,
            'retry_delay_ms' => 500,
            'timeout_seconds' => 30,
        ],
    ],

    // NOT calibrated: engineering-cautious defaults derived from the existing 1–4 second
    // request pause, not an EIS-published quota. Raise only from production observations.
    'eis' => [
        'csv_endpoint' => 'https://zakupki.gov.ru/epz/order/orderCsvSettings/download.html',
        // Подтверждён прогоном P4.7 (K-1): полный блок — ровно 500 строк, дублей
        // реестрового номера ВНУТРИ блока 0 на 194 блоках, отброшенных маппером строк 0.
        // ВАЖНО: фактический cap глубины листинга источника — 5 000 записей на запрос
        // (блок `from=5001` возвращает `dedupSignature` блока `from=4501`), а НЕ ≈10 000,
        // как утверждала `research/eis-csv-recon.md` — см. `findings/P4-load-baseline.md` §3.
        // Block size belongs to crawler.platforms.eis.block_size.

        /*
        | Вкладки детальной карточки (`02-crawling.md` §5.1), опрашиваемые
        | `CsvSourceMethod::collectDetails()`. URL каждой — из `Registry->settings`
        | (`EisCardUrlBuilder`), список тут только задаёт порядок обхода.
        */
        'card_tabs' => ['common-info', 'documents', 'event-journal', 'supplier-results'],

        /*
        | Базовый хост ЕИС — вкладка `documents` отдаёт ссылки на файлы (`filestore/...`)
        | относительными путями, `DocumentsParser` резолвит их в абсолютный URL для скачивания.
        */
        'base_url' => 'https://zakupki.gov.ru',
    ],

    /*
    |--------------------------------------------------------------------------
    | Healthcheck платформы (P5.1)
    |--------------------------------------------------------------------------
    |
    | Здесь только эксплуатационный график и путь. Якоря и пороги живут в
    | versioned эталоне: менять критерий проверки через конфиг запрещено.
    |
    */
    'health' => [
        'enabled' => true,
        'baseline_path' => 'resources/crawler/health/eis-baseline.json',
        'window_hours' => 24,
        'cron' => '0 6 * * *',
        'repeat_alert_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | ОКПД2: пересборка карты «код → id НСИ» (P2.5, D14)
    |--------------------------------------------------------------------------
    |
    | Единственная точка плана, ходящая к живой площадке за справочником: команда
    | `crawler:okpd2-sync`. Эндпоинт и форма ответа сняты живым замером — JSON-массив узлов с
    | полями `code`/`key`/`name`/`hasChildren` (`findings/P1-okpd2-map.md` §1, §6.1).
    |
    */
    'okpd2' => [
        'children_endpoint' => 'https://zakupki.gov.ru/epz/api/nsi/okpd2/children.html',

        // Корень поддерева пересборки — раздел `F` «Сооружения и строительные работы»
        // (`findings/P1-okpd2-probe.md` §5). Именно ПАРАМЕТР, а не константа кода: ветка 223-ФЗ
        // (Q10) и любое расширение пойдут от другого корня, снимаемого тем же запросом.
        'root_external_id' => '8873866',

        // Уровень корня в нумерации карты (раздел = 1): дети получают `root_level + 1`, поэтому
        // классы `41`/`42`/`43` ложатся уровнем 2 — так карту снял P1.2 и засидировал P2.1.
        // Значение читается только когда корня НЕТ в таблице (сам раздел `F` в карту не пишется).
        'root_level' => 1,

        // Потолок запросов на прогон, жёсткий: обход до уровня групп стоит 14 запросов
        // (`findings/P1-okpd2-map.md` §6.1), запас — на появление новых подклассов. Полный обход
        // до листьев (≈541 запрос, §7) сюда сознательно не помещается: остаток честно
        // помечается «не обойдено» в отчёте команды.
        'max_requests' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | TLS trust (P4.12, D125)
    |--------------------------------------------------------------------------
    |
    | Узкая карта «хост => путь к бандлу CA» — единственный носитель правила `SourceTlsTrust`.
    | Сервер ЕИС не досылает промежуточный `Russian Trusted Sub CA` 2024 года, поэтому клиент
    | обязан нести его сам; бандл коммитится в репозиторий и копируется в образ (НЕ в системный
    | трест — это расширило бы доверие российскому УЦ на весь контейнер, включая канал к
    | LLM-провайдеру). Хост без записи в карте = системный трест, не отказ.
    */
    'raw_html_disk' => 's3',
    'raw_html_gzip' => true,
    'raw_html_retention_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Fresh mode
    |--------------------------------------------------------------------------
    |
    | Safety hard-cap на число блоков за один fresh-прогон (`FreshRunProcessor`) —
    | защита от бесконечного цикла, если оба штатных стоп-условия не сработали.
    |
    */
    'fresh_max_blocks' => 40,

    /*
    |--------------------------------------------------------------------------
    | Fresh mode — политика остановки «только актуальные данные» (P3.3)
    |--------------------------------------------------------------------------
    |
    | Запас (мин) от времени СТАРТА предыдущего завершённого прогона, ниже которого
    | `FreshRunProcessor` останавливается (`RunStopPolicy::OnlyFresh`,
    | `research/04-p3-runs-design.md` §4). НЕ калибровано боевым прогоном:
    | занижение = пропущенные обновления, завышение = лишние блоки.
    |
    */
    'fresh' => [
        'only_fresh_margin_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw artifact storage
    |--------------------------------------------------------------------------
    |
    | Диск (`config/filesystems.php`), куда `CsvBlockIngestor` кладёт сырой CSV
    | блока листинга (`raw/csv/{crawlerId}/{runId}/{from}.csv`, `01-database.md` §9).
    | Decision Log D7 — object storage (S3/MinIO) как источник истины для сырых артефактов.
    |
    */
    'raw_disk' => 's3',

    /*
    |--------------------------------------------------------------------------
    | Fallback-цепочка дисков на окно cutover (P6.4, `research/07-storage-addressing.md` §5)
    |--------------------------------------------------------------------------
    |
    | Упорядоченный список ИМЁН дисков от новых поколений к старым — ДАННЫЕ, не ветка кода.
    | Пустой по умолчанию: на сегодняшнем дереве поведение не меняется байт-в-байт, ко второму
    | диску обращения нет вовсе. Ключ заведён следствием D114 (третий носитель `raw`), читателя
    | `raw`-диска (`forEstimateRawResult()`) в резолвере пока нет — ключ ждёт первого читателя.
    |
    */
    'raw_fallback_disks' => [],

    /*
    |--------------------------------------------------------------------------
    | Document storage & retry
    |--------------------------------------------------------------------------
    |
    | Диск для контент-адресованных версий документов (`documents/<hash-prefix>/<sha256>`,
    | `01-database.md` §5, §9) — по умолчанию тот же, что `raw_disk`. Ретраи скачивания —
    | error-блок `prc_documents` (`attempts`/`next_retry_at`/`failed_permanently`).
    */
    'documents_disk' => 's3',
    'documents_allowed_hosts' => ['zakupki.gov.ru'],
    'documents_max_bytes' => 100 * 1024 * 1024,
    'documents_max_redirects' => 3,
    'documents_signature_bytes' => 8192,
    'documents_max_attempts' => 5,
    'documents_retry_backoff_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Fallback-цепочка дисков документов на окно cutover (P6.4, `research/07-storage-addressing.md` §5)
    |--------------------------------------------------------------------------
    |
    | Упорядоченный список ИМЁН дисков от новых поколений к старым — ДАННЫЕ, не ветка кода.
    | Пустой по умолчанию: на сегодняшнем дереве поведение не меняется байт-в-байт.
    | Читатель — `FileLocationResolver::forDocumentVersion()`/`forRepresentation()`.
    |
    */
    'documents_fallback_disks' => [],

    /*
    |--------------------------------------------------------------------------
    | Detail stage (детальная карточка)
    |--------------------------------------------------------------------------
    |
    | `DetailAnalysisProcessor` (`02-crawling.md` §5.2): метод забора карточки (пока
    | сконфигурирован только `csv`), ретраи снятия карточки (error-блок `prc_procurements.
    | detail_*`, тот же паттерн, что `documents_*`), периодичность ре-скана закупок на
    | завершающихся этапах (`evaluation`/`awarded`) до появления итогов.
    */
    'detail_source_method' => SourceMethod::Csv->value,
    'detail_max_attempts' => 5,
    'detail_retry_backoff_minutes' => 15,
    'detail_rescan_interval_minutes' => 60,
    // Ниже supervisor-crawl-detail.timeout=300: catch/finalization получают 30 секунд.
    'detail_deadline_seconds' => 270,

    /*
    |--------------------------------------------------------------------------
    | Archive mode
    |--------------------------------------------------------------------------
    |
    | Archive-режим (`02-crawling.md` §4.2, D20/D21): потолок ценового work-стека
    | старта окна (`priceFromGeneral`/`priceToGeneral`), минимальный интервал-стоп
    | рекурсии дробления (допустимая потеря на краях — датасет), safety hard-cap
    | блоков на один ценовой интервал, формат даты для `publishDateFrom/To` и
    | `updateDateFrom/To` (оба параметра подтверждены живой разведкой —
    | `findings/P4-eis-recon.md` §2: окно `01.08.2026` → 171 запись, у всех «Дата
    | обновления» = `01.08.2026`, границы включительные).
    |
    | Значения КАЛИБРОВАНЫ боевым прогоном P4.7 (`findings/P4-load-baseline.md` §7),
    | метка «калибруются на живых данных» снята. Факты-основания — там же.
    |
    */
    'archive' => [
        // Подтверждён прогоном: максимум НМЦК корпуса 19 160 закупок = 987 236 134 ₽,
        // записей выше потолка — 0 (P4.7, K-3). Потолок не занижается: он же — старт
        // ценового стека, а профиль сужает его своим `priceTo` (`HasPriceRange`).
        'price_ceiling' => 1_000_000_000,
        // Дно рекурсии этим прогоном НЕ калибруется: `price interval at minimum` не
        // сработал ни разу на 194 блоках (P4.7, K-2) — значение остаётся стартовым.
        'min_price_interval' => 1000,
        // Калибровано: наблюдённый максимум блоков на один ценовой интервал = 11
        // (cap источника 5 000 записей / `eis.block_size` 500 = 10 полных блоков + 1
        // блок-детектор повтора `dedupSignature`), среднее 6.67 на 27 интервалах
        // прогона 20.11.2025. Прежние 100 недостижимы по построению — safety-cap не
        // сработал бы никогда (P4.7, Р-8).
        'max_blocks_per_window' => 12,
        'date_format' => 'd.m.Y',
    ],

    /*
    |--------------------------------------------------------------------------
    | Change tracking (research/05-change-tracking.md, P4.6)
    |--------------------------------------------------------------------------
    |
    | Перекрытие окна archive-рескана простоя (`window_overlap_days` — старт со сдвигом
    | назад: гранулярность источника сутки, разброс часовых поясов заказчиков до МСК+9,
    | цена ограничена идемпотентностью повторного прохода по `snapshot_hash`, §2.1) и
    | плановый суточный delta-scan окна обновления (`delta_scan_enabled`/`delta_scan_cron`,
    | §2.2) — выключен по умолчанию, включает владелец ПОСЛЕ боевого прогона P4.7 (как и
    | fresh-профиль, D37).
    |
    */
    'tracking' => [
        'window_overlap_days' => 2,
        'delta_scan_enabled' => true,
        'delta_scan_cron' => '0 23 * * *',
    ],
];
