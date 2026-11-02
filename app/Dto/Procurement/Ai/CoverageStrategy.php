<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class CoverageStrategy
{
    public function __construct(
        public int $windowSpan,
        public int $overlap,
        public int $minSpan = 1,
        public int $maxDepth = 16,
        public int $maxSplits = 255,
        public bool $allowDegradedRepresentation = false,
    ) {
        if ($windowSpan < 1 || $overlap < 0 || $overlap >= $windowSpan || $minSpan < 1 || $minSpan > $windowSpan
            || $maxDepth < 1 || $maxDepth > 16 || $maxSplits < 1 || $maxSplits > 255) {
            throw new \InvalidArgumentException('Invalid coverage/split policy.');
        }
    }

    /** @return array<string, int|bool> */
    public function fields(): array
    {
        return ['windowSpan' => $this->windowSpan, 'overlap' => $this->overlap, 'minSpan' => $this->minSpan, 'maxDepth' => $this->maxDepth, 'maxSplits' => $this->maxSplits, 'allowDegradedRepresentation' => $this->allowDegradedRepresentation];
    }
}
