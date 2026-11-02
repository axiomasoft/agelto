<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

/** A provider codec result, before canonical model-schema/semantic validation. */
final readonly class GenerationResponse
{
    public function __construct(public string $modelJson, public string $finishReason, public bool $unexpectedContent = false) {}
}
