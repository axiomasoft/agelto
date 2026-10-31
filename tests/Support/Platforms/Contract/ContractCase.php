<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

final readonly class ContractCase
{
    /** @param non-empty-list<string> $fixtures
     * @param  \Closure(): SemanticObservation  $observe
     */
    public function __construct(
        public string $capability,
        public string $scenario,
        public array $fixtures,
        public SemanticObservation $expected,
        public \Closure $observe,
    ) {}

    public function key(): string
    {
        return $this->capability.'.'.$this->scenario;
    }
}
