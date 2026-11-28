<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Enums\Procurement\Process\Core\StageCode;

/**
 * Контракт типизированного набора правил парсинга для конкретной стадии.
 */
interface StageParsingRules
{
    public function stage(): StageCode;

    public function format(): ParsingFormat;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
