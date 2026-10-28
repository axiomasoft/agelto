<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Repositories\Procurement\Process\ProjectionRepository;
use App\Services\Procurement\Process\ProjectionRegistry;
use App\Services\Procurement\Process\RetentionService;
use DateTimeImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Один bounded batch retention transition history (observability#s07). --dry-run только считает
 * кандидатов. Maintenance rebuild откладывает batch (код 0, ничего не удалено); повтор безопасен.
 * Каждый вывод сообщает полноту истории projectors: после prune rebuild недоступен (history_unavailable),
 * поэтому архив не выдаёт себя за полный.
 */
#[Description('Удаляет один batch устаревших transition events с подтверждёнными projections (--dry-run только считает)')]
#[Signature('process:prune-history {--dry-run : только показать число кандидатов}')]
final class PruneHistoryCommand extends Command
{
    public function handle(RetentionService $retention, ProjectionRegistry $registry, ProjectionRepository $projections): int
    {
        $now = new DateTimeImmutable;
        if ($this->option('dry-run')) {
            $this->info('Кандидатов в этом batch: '.count($retention->candidates($now)).'.');
            $this->reportCompleteness($registry, $projections);

            return self::SUCCESS;
        }
        $result = $retention->prune($now);
        if ($result->deferredForMaintenance) {
            $this->warn('Rebuild в maintenance: batch отложен, ничего не удалено.');
            $this->reportCompleteness($registry, $projections);

            return self::SUCCESS;
        }
        $this->info("Удалено {$result->pruned}, оставлено до подтверждения projections {$result->ineligible}.");
        $this->reportCompleteness($registry, $projections);

        return self::SUCCESS;
    }

    private function reportCompleteness(ProjectionRegistry $registry, ProjectionRepository $projections): void
    {
        $incomplete = [];
        foreach (array_keys($registry->all()) as $key) {
            $state = $projections->state($key);
            if ($state !== null && ! $state->historyComplete) {
                $incomplete[] = $key;
            }
        }
        if ($incomplete === []) {
            $this->info('История всех projectors полна: rebuild доступен.');

            return;
        }
        $this->warn('История неполна, rebuild недоступен (history_unavailable): '.implode(', ', $incomplete).'.');
    }
}
