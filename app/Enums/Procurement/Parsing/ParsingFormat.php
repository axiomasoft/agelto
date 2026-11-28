<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Parsing;

/**
 * Формат исходных данных, обрабатываемый правилом парсинга.
 */
enum ParsingFormat: string
{
    case Html = 'html';
    case Csv = 'csv';
    case Json = 'json';
}
