<?php

declare(strict_types=1);

use App\Services\Platforms\Eis\EisDefinition;

return [
    // Nested config/platforms/<code>.php is loaded under platforms.<code>.
    'definitions' => [
        'eis' => EisDefinition::class,
    ],
];
