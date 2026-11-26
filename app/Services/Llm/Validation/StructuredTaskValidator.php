<?php

declare(strict_types=1);

namespace App\Services\Llm\Validation;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Llm\Requests\StructuredTask;
use App\Enums\Llm\StructuredDisposition as Decision;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use Spatie\LaravelData\Data;

/** Parses stored model JSON only; never sends, repairs, continues or schedules a paid call. */
final readonly class StructuredTaskValidator
{
    public function __construct(private CanonicalSchemaValidator $schemas) {}

    /**
     * @template T of Data
     *
     * @param  StructuredTask<T>  $task
     * @return StructuredResult<T>
     */
    public function validate(StructuredTask $task, string $raw, string $finishReason, bool $expectedNonempty = true, bool $unexpectedContent = false): StructuredResult
    {
        if ($unexpectedContent) {
            return new StructuredResult(Decision::Unsupported);
        }
        if ($finishReason === 'MAX_TOKENS') {
            return new StructuredResult(Decision::SplitRequired);
        }
        if (in_array($finishReason, ['SAFETY', 'REFUSAL'], true)) {
            return new StructuredResult(Decision::Refusal);
        }
        if ($finishReason !== 'STOP') {
            return new StructuredResult(Decision::Unsupported);
        }
        if ($raw === '' && $expectedNonempty) {
            return new StructuredResult(Decision::Incomplete);
        }
        $validation = $this->schemas->validate($raw, $task->schema);
        if ($validation->splitRequired) {
            return new StructuredResult(Decision::SplitRequired, errors: $validation->errors);
        }
        if (! $validation->valid()) {
            return new StructuredResult(Decision::InvalidSchema, errors: $validation->errors);
        }
        $decoded = json_decode($raw, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return new StructuredResult(Decision::InvalidSchema, errors: ['object_required']);
        }
        $data = $task->responseType::from($decoded);
        $errors = $task->validator->errors($data);
        if (in_array('empty_required_output', $errors, true)) {
            return new StructuredResult(Decision::Incomplete, errors: $errors);
        }

        return $errors === []
            ? new StructuredResult(Decision::Accepted, $data)
            : new StructuredResult(Decision::InvalidSemantics, errors: $errors);
    }
}
