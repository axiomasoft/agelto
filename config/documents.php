<?php

declare(strict_types=1);

return [
    'download' => [
        'max_bytes' => 268435456,
        'revalidation_seconds' => 2592000,
        'durable_domain' => 'documents.originals',
        'temporary_domain' => 'documents.temporary',
        'disk' => 'local',
        'temporary_disk' => 'local',
    ],
];
