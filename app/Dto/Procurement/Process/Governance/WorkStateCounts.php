<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\WorkState;

/** Число work по каждому из девяти состояний; значения неотрицательны, неизвестный ключ — ошибка. */
final readonly class WorkStateCounts
{
    public function __construct(
        public int $ready = 0,
        public int $admitted = 0,
        public int $running = 0,
        public int $deferred = 0,
        public int $blocked = 0,
        public int $succeeded = 0,
        public int $failedItem = 0,
        public int $cancelled = 0,
        public int $superseded = 0,
    ) {
        foreach ([$ready, $admitted, $running, $deferred, $blocked, $succeeded, $failedItem, $cancelled, $superseded] as $count) {
            ProcessValue::nonNegative($count);
        }
    }

    /** @param array<string, int> $byState ключи — значения WorkState */
    public static function fromMap(array $byState): self
    {
        $counts = array_fill_keys(array_map(static fn (WorkState $state): string => $state->value, WorkState::cases()), 0);
        foreach ($byState as $state => $count) {
            if (WorkState::tryFrom((string) $state) === null) {
                throw new \LogicException('Unknown work state in run links: '.$state);
            }
            $counts[$state] += $count;
        }

        return new self(
            $counts['ready'], $counts['admitted'], $counts['running'], $counts['deferred'], $counts['blocked'],
            $counts['succeeded'], $counts['failed_item'], $counts['cancelled'], $counts['superseded'],
        );
    }

    /** Работа, которая продвигается: ready/admitted/running. */
    public function active(): int
    {
        return $this->ready + $this->admitted + $this->running;
    }

    /** Работа, ожидающая due или оператора: deferred/blocked. */
    public function waiting(): int
    {
        return $this->deferred + $this->blocked;
    }

    public function terminal(): int
    {
        return $this->succeeded + $this->failedItem + $this->cancelled + $this->superseded;
    }

    public function total(): int
    {
        return $this->active() + $this->waiting() + $this->terminal();
    }
}
