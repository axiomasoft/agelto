<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum AuthHeaderScheme: string
{
    case XGoogApiKey = 'x-goog-api-key';
    case Bearer = 'bearer';
    case XApiKey = 'x-api-key';
}
