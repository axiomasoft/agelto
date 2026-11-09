<?php

declare(strict_types=1);
use App\Services\Procurement\Document\CatalogDocumentAccess;

// Config-only Definition policy, used by validation and explicit catalog sync.
return [
    'okpd2' => [
        'children_endpoint' => 'https://zakupki.gov.ru/epz/api/nsi/okpd2/children.html',

        // Корень поддерева пересборки — раздел `F` «Сооружения и строительные работы»
        // задаётся параметром: ветка 223-ФЗ
        // и любое расширение пойдут от другого корня, снимаемого тем же запросом.
        'root_external_id' => '8873866',

        // Уровень корня в нумерации карты (раздел = 1): дети получают `root_level + 1`, поэтому
        // классы `41`/`42`/`43` ложатся уровнем 2.
        // Значение читается только когда корня НЕТ в таблице (сам раздел `F` в карту не пишется).
        'root_level' => 1,

        // Потолок запросов на прогон, жёсткий: обход до уровня групп стоит 14 запросов
        // с запасом на появление новых подклассов. Полный обход
        // до листьев сюда сознательно не помещается: остаток честно
        // помечается «не обойдено» в отчёте команды.
        'max_requests' => 20,
    ],
    'documents_allowed_hosts' => ['zakupki.gov.ru'],

    'block_size' => 500,
    'document_access_resolver' => CatalogDocumentAccess::class,
    'source_timezone' => env('EIS_SOURCE_TIMEZONE', 'Europe/Moscow'),
    'budgets' => [
        // Общий потолок источника (масштабируется пулом прокси).
        'origin_budget' => ['rate_per_minute' => 60, 'burst' => 2, 'max_inflight' => 10],
        // Безопасный лимит одного прокси-маршрута под WAF ЕИС (6 req/min, burst=1, max_inflight=1).
        'route_origin_budget' => ['rate_per_minute' => 6, 'burst' => 1, 'max_inflight' => 1],
        // Лимит внешнего IP (egress_id), чтобы два маршрута с одним IP не удваивали квоту.
        'egress_origin_budget' => ['rate_per_minute' => 6, 'burst' => 1, 'max_inflight' => 1],
    ],
    'stage_route_profiles' => [
        'listing' => env('EIS_LISTING_ROUTE_PROFILE', 'eis-listing'),
        'detail' => env('EIS_DETAIL_ROUTE_PROFILE', 'eis-detail'),
        'status_refresh' => env('EIS_STATUS_ROUTE_PROFILE', 'eis-detail'),
    ],
    'listing_bounds' => [
        'max_blocks' => 12,
        'min_interval_cents' => 100000,
        'max_depth' => 20,
        'max_partitions' => 10000,
        'parser_seconds' => 5,
    ],
    'endpoints' => [
        'listing' => [
            'target' => 'csv',
            'url' => 'https://zakupki.gov.ru/epz/order/orderCsvSettings/download.html',
            'profile' => [
                'code' => 'eis.csv_export',
                'connect_seconds' => 5,
                'low_speed_seconds' => 30,
                'total_seconds' => 90,
                'max_entity_bytes' => 16777216,
                'max_text_bytes' => 50331648,
                'redirects' => 3,
                'attempts' => 3,
                'distinct_routes' => 2,
                'delays_ms' => [200, 600],
                'response_kind' => 'text',
                'charset_default' => 'Windows-1251',
                'version' => 1,
            ],
        ],
        'card' => [
            'target' => 'card',
            'url' => 'https://zakupki.gov.ru/epz/order/notice/',
            'profile' => [
                'code' => 'eis.card_tab',
                'connect_seconds' => 5,
                'low_speed_seconds' => 15,
                'total_seconds' => 30,
                'max_entity_bytes' => 8388608,
                'max_text_bytes' => 25165824,
                'redirects' => 3,
                'attempts' => 3,
                'distinct_routes' => 2,
                'delays_ms' => [200, 600],
                'response_kind' => 'text',
                'charset_default' => 'Windows-1251',
                'version' => 1,
            ],
        ],
        'document' => [
            'target' => 'document.'.substr(hash('sha256', 'origin:v1:https://zakupki.gov.ru:443'), 0, 16),
            'url' => 'https://zakupki.gov.ru/',
            'profile' => [
                'code' => 'eis.document',
                'connect_seconds' => 5,
                'low_speed_seconds' => 30,
                'total_seconds' => 240,
                'max_entity_bytes' => 268435456,
                'max_text_bytes' => null,
                'redirects' => 3,
                'attempts' => 3,
                'distinct_routes' => 2,
                'delays_ms' => [200, 600],
                'response_kind' => 'binary',
                'charset_default' => null,
                'version' => 1,
            ],
        ],
        'diagnostic' => [
            'target' => 'eis.diagnostic',
            'url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/results.html',
            'profile' => [
                'code' => 'eis.diagnostics',
                'connect_seconds' => 5,
                'low_speed_seconds' => 10,
                'total_seconds' => 20,
                'max_entity_bytes' => 2097152,
                'max_text_bytes' => 6291456,
                'redirects' => 3,
                'attempts' => 2,
                'distinct_routes' => 2,
                'delays_ms' => [200],
                'response_kind' => 'text',
                'charset_default' => 'Windows-1251',
                'version' => 1,
            ],
        ],
    ],
];
