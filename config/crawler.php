<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Services\Procurement\Eis\CsvSourceMethod;

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
    | Proxy resolver: политика выбора и circuit breaker (research/02 §6-7, P1.4)
    |--------------------------------------------------------------------------
    |
    | Все значения ниже НЕКАЛИБРОВАНЫ — калибровка боевым прогоном (P4.7). Гео-политика
    | по скоупу (запрещённые/требуемые страны) сюда не входит — заводится P1.5.
    |
    */
    'proxy' => [
        'resolver' => [
            // Веса слагаемых score: score = w_success*success_ratio - w_latency*latency_norm
            // - w_streak*streak_norm (research/02 §6).
            'weights' => [
                'success' => 1.0,
                'latency' => 0.3,
                'streak' => 0.5,
            ],
            // Задержка (мс), при которой штраф латентности максимален.
            'latency_ref_ms' => 5000,
            // Серия ГЛОБАЛЬНЫХ отказов, при которой штраф серии максимален.
            'streak_ref' => 5,
            // Ширина случайного выбора (weighted random внутри top-K, D6).
            'top_k' => 3,
            // Вес свежего исхода в EWMA success_ratio/latency_ms_avg (prc_proxy_targets).
            'ewma_alpha' => 0.3,
            // Нижняя граница веса — даже худший из top-K остаётся достижим.
            'epsilon' => 0.01,
        ],

        'breaker' => [
            // Серия подряд отказов до открытия breaker'а — по умолчанию для уровня.
            'threshold' => [
                'global' => 5,
                'target' => 3,
            ],
            // Базовый cooldown (минуты) при первом открытии; экспоненциально растёт
            // до cap (research/02 §7: base * 2^(open_count-1)).
            'base_cooldown_minutes' => [
                'global' => 30,
                'target' => 15,
            ],
            // Верхняя граница cooldown (часы), обе уровня.
            'cap_hours' => 6,
            // Единственность probe в half-open — секунды удержания пробы (§7).
            'probe_lock_seconds' => 60,
            // http_429 — троттлинг, не бан: узел здоров, ему нужна короткая пауза (§5, §7).
            'http_429' => [
                'threshold' => 10,
                'base_cooldown_minutes' => 2,
            ],
        ],

        /*
        | Гео-политика по скоупу (research/02 §8, P1.5, D5) — жёсткий фильтр ДО скоринга внутри
        | резолвера, не вес: запрет обязан быть гарантией. Узел без `country` не проходит гейт
        | скоупа с непустым `forbidden_countries` (неизвестная страна = запрещённая). Только
        | скоуп `llm` несёт запрет сегодня (требование брифа «не запустить Gemini через российский
        | прокси»); `env`-override — под калибровку без деплоя.
        |
        | `required_country` (research/02 §8, P1.9, D83) — вторая половина той же политики: пол
        | площадки для `crawl_*`/`document_download` («кроулинг ЕИС обязан идти через российский
        | прокси»), `null` у `llm` (требование — запрет, не привязка к стране). Складывается с
        | `ProxyRequirements->country` профиля, не заменяет его (Implementation Rules P1.9).
        */
        'policy' => [
            ProxyScope::Llm->value => [
                'forbidden_countries' => array_filter(array_map(
                    'trim',
                    explode(',', (string) env('CRAWLER_PROXY_LLM_FORBIDDEN_COUNTRIES', 'RU')),
                )),
                'required_country' => env('CRAWLER_PROXY_LLM_REQUIRED_COUNTRY'),
            ],
            ProxyScope::CrawlListing->value => [
                'forbidden_countries' => [],
                'required_country' => env('CRAWLER_PROXY_CRAWL_LISTING_REQUIRED_COUNTRY', 'RU'),
            ],
            ProxyScope::CrawlDetail->value => [
                'forbidden_countries' => [],
                'required_country' => env('CRAWLER_PROXY_CRAWL_DETAIL_REQUIRED_COUNTRY', 'RU'),
            ],
            ProxyScope::DocumentDownload->value => [
                'forbidden_countries' => [],
                'required_country' => env('CRAWLER_PROXY_DOCUMENT_DOWNLOAD_REQUIRED_COUNTRY', 'RU'),
            ],
        ],
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

        /*
        | Базовый хост ЕИС — вкладка `documents` отдаёт ссылки на файлы (`filestore/...`)
        | относительными путями, `DocumentsParser` резолвит их в абсолютный URL для скачивания.
        */
        'base_url' => 'https://zakupki.gov.ru',
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
    'documents_max_attempts' => 5,
    'documents_retry_backoff_minutes' => 15,

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

    /*
    |--------------------------------------------------------------------------
    | Archive mode
    |--------------------------------------------------------------------------
    |
    | Archive-режим (`02-crawling.md` §4.2, D20/D21): потолок ценового work-стека
    | старта окна (`priceFromGeneral`/`priceToGeneral`), минимальный интервал-стоп
    | рекурсии дробления (допустимая потеря на краях — датасет), safety hard-cap
    | блоков на один ценовой интервал, формат даты для `publishDateFrom/To`
    | (`updateDateFrom/To` — экстраполяция, D24). Стартовые значения калибруются
    | на живых данных (см. handoff Open risks).
    |
    */
    'archive' => [
        'price_ceiling' => 1_000_000_000,
        'min_price_interval' => 1000,
        'max_blocks_per_window' => 100,
        'date_format' => 'd.m.Y',
    ],
];
