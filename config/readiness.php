<?php

return [
    'cache' => [
        'ttl_seconds' => 30,
    ],
    'storage' => [
        'disk' => env(key: 'READINESS_STORAGE_DISK', default: env(key: 'FILESYSTEM_DISK', default: 'local')),
    ],
    'scheduler' => [
        'ttl_minutes' => 10,
        'max_age_minutes' => 2,
    ],
];
