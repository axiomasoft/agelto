<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Validation\SemanticValidator;
use Spatie\LaravelData\Data;

/** @template T of Data */
final readonly class StructuredTask
{
    public CanonicalSchema $schema;

    /**
     * @param  class-string<T>  $responseType
     * @param  SemanticValidator<T>  $validator
     */
    public function __construct(
        public string $responseType,
        public PromptSnapshot $prompt,
        public TaskPurpose $purpose,
        public SemanticValidator $validator,
    ) {
        $this->schema = new CanonicalSchema((new JsonSchemaGenerator)->generate($responseType, canonical: true));
        (new CanonicalSchemaValidator)->assertSchema($this->schema);
    }
}
