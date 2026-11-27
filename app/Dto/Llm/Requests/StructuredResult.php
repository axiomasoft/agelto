<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Enums\Llm\StructuredDisposition;
use Spatie\LaravelData\Data;

/** @template-covariant T of Data = never */
final readonly class StructuredResult
{
    /**
     * @param  T|null  $data
     * @param  list<string>  $errors
     */
    public function __construct(public StructuredDisposition $disposition, public ?Data $data = null, public array $errors = []) {}
}
