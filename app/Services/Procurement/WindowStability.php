<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Models\Procurement\Crawler\Run;

/** Two identical complete passes are required before acknowledging a date/price window. */
final readonly class WindowStability
{
    public function __construct(private CrawlerRunManager $runs) {}

    public function observe(Run $run, string $window, ListingBatch $batch, bool $terminal): bool
    {
        $state = $run->stats?->get('window_verification');
        if (! is_array($state) || ($state['window'] ?? null) !== $window) {
            $state = ['window' => $window, 'digest' => '', 'previous' => null, 'pass' => 1];
        }
        $digest = $state['digest'] ?? '';
        $pass = $state['pass'] ?? 1;
        if (! is_string($digest) || ! is_int($pass)) {
            throw new \LogicException('Invalid persisted window verification state.');
        }
        $state['digest'] = hash('sha256', $digest.json_encode($batch->rows, JSON_THROW_ON_ERROR));
        $hold = false;
        $stable = $terminal && ($state['previous'] ?? null) === $state['digest'];
        if ($terminal && ! $stable) {
            if ($pass >= 3) {
                $hold = true;
            }
            $state['previous'] = $state['digest'];
            $state['digest'] = '';
            $state['pass'] = $pass + 1;
        }
        if (! $this->runs->updateStats(run: $run, stats: array_merge($run->stats?->toArray() ?? [], ['window_verification' => $stable ? null : $state]))) {
            throw ExecutionLeaseLost::noLongerOwned();
        }
        if ($hold) {
            $this->runs->pause(run: $run, reason: 'Окно источника меняется между полными проходами; полнота не подтверждена.');
        }

        return $stable;
    }

    public function restartInterruptedPass(Run $run): void
    {
        $state = $run->stats?->get('window_verification');
        if (is_array($state)) {
            $state['digest'] = '';
            $this->runs->updateStats(run: $run, stats: array_merge($run->stats->toArray(), ['window_verification' => $state]));
        }
    }
}
