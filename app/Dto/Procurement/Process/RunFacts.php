<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\DiscoveryOutcome;

/**
 * Authoritative входы reducer конечного run: cancel flag, discovery contract, факт activation
 * (run стартовал либо имеет хотя бы один link) и состояния ТОЛЬКО required work. Observes не
 * удерживает acquisition run и сюда не попадает.
 */
final readonly class RunFacts
{
    public function __construct(
        public bool $cancelRequested,
        public DiscoveryOutcome $discovery,
        public bool $activated,
        public WorkStateCounts $required,
    ) {}
}
