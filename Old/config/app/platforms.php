<?php

declare(strict_types=1);

use App\Enums\Platform;

return [
    Platform::EIS->value => [
        'name' => Platform::EIS->getLabel(),
        'url' => 'https://zakupki.gov.ru',
    ],
    Platform::Local->value => [
        'name' => Platform::Local->getLabel(),
        'url' => env('APP_URL'),
    ],
];
