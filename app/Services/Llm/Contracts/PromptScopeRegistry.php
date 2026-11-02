<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Enums\Llm\DocumentScope;
use App\Enums\Llm\PromptStep;

interface PromptScopeRegistry
{
    public function documentType(string $value): DocumentScope;

    public function promptStepFor(DocumentScope $documentType, string $value): PromptStep;
}
