<?php

declare(strict_types=1);

use App\Enums\Crawler\SourceMethod;
use App\Services\Crawler\Eis\CsvSourceMethod;

return [
    /*
    |--------------------------------------------------------------------------
    | Source methods
    |--------------------------------------------------------------------------
    |
    | Карта `SourceMethod`-значение → класс-реализация `Contracts\SourceMethod`.
    | Резолвится через контейнер (`SourceMethodFactory`), не через `match`-лестницу
    | (config-driven-abstraction). `html`/`api` — задел контракта, реализация вне
    | плана V2-CRAWL; классов для них здесь намеренно нет.
    |
    */
    'source_methods' => [
        SourceMethod::Csv->value => CsvSourceMethod::class,
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
    | Proxy pool & polite throttle
    |--------------------------------------------------------------------------
    |
    | Порог отказов подряд, после которого `ProxyPool::reportFailure()` дизейблит
    | узел (`is_active=false`); границы паузы между запросами (`PoliteThrottle`).
    |
    */
    'proxy_max_fails' => 5,

    'throttle' => [
        'min_ms' => 1000,
        'max_ms' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Ретраи и таймаут для `Http`-запросов `SourceMethod`-реализаций к площадкам.
    |
    */
    'http' => [
        'retries' => 3,
        'base_delay_ms' => 500,
        'timeout' => 30,
    ],

    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',

    /*
    |--------------------------------------------------------------------------
    | ЕИС (zakupki.gov.ru)
    |--------------------------------------------------------------------------
    |
    | Endpoint CSV-экспорта реестра закупок и размер блока листинга (`from`..`to`).
    |
    */
    'eis' => [
        'csv_endpoint' => 'https://zakupki.gov.ru/epz/order/orderCsvSettings/download.html',
        'block_size' => 500,

        /*
        | Вкладки детальной карточки (`02-crawling.md` §5.1), опрашиваемые
        | `CsvSourceMethod::collectDetails()`. URL каждой — из `Registry->settings`
        | (`EisCardUrlBuilder`), список тут только задаёт порядок обхода.
        */
        'card_tabs' => ['common-info', 'documents', 'event-journal', 'supplier-results'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw HTML snapshot storage (детальная карточка)
    |--------------------------------------------------------------------------
    |
    | Сырой HTML вкладок карточки — расходник (`01-database.md` §9): retention
    | по умолчанию выключен, диск по умолчанию — тот же, что `raw_disk`.
    |
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
    | Raw artifact storage
    |--------------------------------------------------------------------------
    |
    | Диск (`config/filesystems.php`), куда `CsvBlockIngestor` кладёт сырой CSV
    | блока листинга (`raw/csv/{crawlerId}/{runId}/{from}.csv`, `01-database.md` §9).
    | Decision Log D7 — object storage (S3/MinIO) как источник истины для сырых артефактов.
    |
    */
    'raw_disk' => 's3',
];
