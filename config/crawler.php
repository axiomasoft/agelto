<?php

declare(strict_types=1);

// Remaining document/reference configuration; acquisition execution belongs to Process.
return [
    'stages' => [
        'document' => ['min_pause_ms' => 1000, 'max_pause_ms' => 4000, 'retries' => 5, 'retry_delay_ms' => 500, 'timeout_seconds' => 30],
    ],
    'health' => [
        'enabled' => true,
        'baseline_path' => 'resources/crawler/health/eis-baseline.json',
        'platform_baselines' => ['eis' => 'resources/crawler/health/eis-baseline.json'],
        'window_hours' => 24,
        'cron' => '0 6 * * *',
        'repeat_alert_hours' => 24,
    ],
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
    'raw_html_disk' => 's3',
    'raw_html_gzip' => true,
    'raw_html_retention_enabled' => false,
    'raw_disk' => 's3',
    'raw_fallback_disks' => [],
    'documents_disk' => 's3',
    'documents_allowed_hosts' => ['zakupki.gov.ru'],
    'documents_max_bytes' => 100 * 1024 * 1024,
    'documents_max_redirects' => 3,
    'documents_signature_bytes' => 8192,
    'documents_max_attempts' => 5,
    'documents_retry_backoff_minutes' => 15,
    'documents_fallback_disks' => [],
];
