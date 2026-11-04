<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Services\Procurement\Process\ProjectionRebuilder;
use App\Services\Procurement\Process\ProjectionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bounded maintenance rebuild counters одного projector из ledger. Не запускает
 * work, producers и внешние эффекты. Отказ (busy, неполная история, превышение размера/deadline,
 * неизвестный факт) оставляет прежнюю generation и receipts нетронутыми и завершается кодом 1.
 */
#[Description('Пересобирает counters projector из ledger одной bounded generation (не запускает work)')]
#[Signature('process:metrics-rebuild {projector : projector_key зарегистрированного consumer}')]
final class MetricsRebuildCommand extends Command
{
    public function handle(ProjectionRebuilder $rebuilder, ProjectionRegistry $registry): int
    {
        $key = (string) $this->argument('projector');
        if (! array_key_exists($key, $registry->all())) {
            $this->error('Неизвестный projector. Допустимо: '.implode(', ', array_keys($registry->all())).'.');

            return self::INVALID;
        }
        $result = $rebuilder->rebuild($key);
        if (! $result->rebuilt()) {
            $this->error("Rebuild {$key} отклонён: {$result->status->value}. Active generation и receipts не изменены.");

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Rebuild %s: generation %d, событий %d (ранее без receipt %d), серий %d (было %d), digest %s.',
            $key, $result->newGeneration ?? 0, $result->processedEvents, $result->previouslyUnapplied,
            count($result->newTotals), count($result->oldTotals), $result->digest ?? '-',
        ));

        return self::SUCCESS;
    }
}
