<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\RebuildStatus;

/**
 * Отчёт bounded rebuild: old/new totals и число событий, которым rebuild впервые выдал receipt.
 * Отказ несёт только статус: прежняя generation и receipts не менялись.
 */
final readonly class RebuildResult
{
    /**
     * @param  list<MetricTotalRow>  $oldTotals
     * @param  list<MetricTotalRow>  $newTotals
     */
    public function __construct(
        public RebuildStatus $status,
        public ?int $newGeneration = null,
        public int $processedEvents = 0,
        public int $previouslyUnapplied = 0,
        public ?string $digest = null,
        public array $oldTotals = [],
        public array $newTotals = [],
    ) {}

    public static function refused(RebuildStatus $status): self
    {
        return new self($status);
    }

    public function rebuilt(): bool
    {
        return $this->status === RebuildStatus::Rebuilt;
    }
}
