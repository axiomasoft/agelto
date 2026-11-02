<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Validation\StructuredTaskValidator;

final class ScreeningContract
{
    /** @return StructuredTask<ScreeningResult> */
    public function task(PromptSnapshot $prompt): StructuredTask
    {
        return new StructuredTask(ScreeningResult::class, $prompt, TaskPurpose::Screening, new ScreeningSemanticValidator);
    }

    /** Checkpoint/result decoding is a schema boundary, just like initial model hydration. */
    public function restore(string $json): ScreeningResult
    {
        $task = $this->task(new PromptSnapshot('Local screening result validation.', 1));
        $result = (new StructuredTaskValidator(new CanonicalSchemaValidator))->validate($task, $json, 'STOP');
        if ($result->disposition !== StructuredDisposition::Accepted || ! $result->data instanceof ScreeningResult) {
            throw new \InvalidArgumentException('Invalid stored screening result.');
        }

        return $result->data;
    }
}
