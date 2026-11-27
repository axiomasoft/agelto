<?php

declare(strict_types=1);

namespace App\Dto\Llm\Schema;

final readonly class ProviderSchema
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $localOnlyConstraints
     */
    public function __construct(public array $definition, public array $localOnlyConstraints, public string $canonicalHash) {}
}
