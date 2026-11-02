<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Schema\ProviderSchema;

/** Immutable text generation input; file/cache assets have their separate owning operations. */
final readonly class ProviderGenerationInput
{
    public function __construct(public PromptSnapshot $system, public string $user, public ProviderSchema $schema)
    {
        if ($user === '' || strlen($user) > 8_388_608) {
            throw new \InvalidArgumentException('Invalid bounded generation input.');
        }
    }
}
