<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalSchemaValidator;

/** One immutable semantic step, independent of the billable operation purpose. */
final readonly class TaskSnapshot
{
    public function __construct(public TaskPurpose $purpose, public PromptSnapshot $prompt, public CanonicalSchema $schema,
        public int $contractVersion = 1)
    {
        if ($contractVersion !== 1) {
            throw new \InvalidArgumentException('Unsupported task snapshot contract version.');
        }
        (new CanonicalSchemaValidator)->assertSchema($schema);
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return ['purpose' => $this->purpose->value, 'contract_version' => $this->contractVersion,
            'prompt_content' => $this->prompt->content, 'prompt_version' => $this->prompt->version, 'prompt_hash' => $this->prompt->hash,
            'schema' => $this->schema->object(), 'schema_hash' => $this->schema->hash];
    }
}
