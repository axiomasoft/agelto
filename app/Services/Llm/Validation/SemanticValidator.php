<?php

declare(strict_types=1);

namespace App\Services\Llm\Validation;

use Spatie\LaravelData\Data;

/** @template T of Data */
interface SemanticValidator
{
    /**
     * @param  T  $data
     * @return list<string>
     */
    public function errors(Data $data): array;
}
