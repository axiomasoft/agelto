<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Process\Core\WorkState;

/** Типизированное решение по финализации/отложению/блокировке текущей work под lock. */
final readonly class FinalizationDecision
{
    /**
     * @param  array{0: string, 1: list<mixed>}  $due
     * @param  list<WorkInput>  $successors
     */
    public function __construct(
        public WorkState $state,
        public string $terminalKind,
        public ?string $reason = null,
        public array $due = ['NULL', []],
        public bool $providerRecovery = false,
        public ?WorkCheckpointEnvelope $checkpoint = null,
        public bool $clearCheckpoint = false,
        public bool $progressSaved = false,
        public int $itemIncrement = 0,
        public ?ContentHash $faultSignature = null,
        public ?int $exhausted = null,
        public array $successors = [],
    ) {}
}
