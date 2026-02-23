<?php

declare(strict_types=1);

use Platform\Process\DocumentPrepare\Strategies\ArchiveStrategy;
use Platform\Process\DocumentPrepare\Strategies\ExcelStrategy;

return [
    'estimate' => [
        'ignore_rules' => [
            [
                'name' => 'Поставка продуктов питания',
                'field' => 'subject',
            ],
        ],
    ],

    'document_prepare' => [
        'strategies' => [
            'excel' => ExcelStrategy::class,
            'archive' => ArchiveStrategy::class,
        ],
    ],
];
