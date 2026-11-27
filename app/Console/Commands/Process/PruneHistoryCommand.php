<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Repositories\Procurement\Process\Projection\ProjectionRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Projection\ProjectionRegistry;
use App\Services\Procurement\Process\Projection\RetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Ограниченная серия batch retention transition history. --dry-run считает кандидатов первого batch.
 * Maintenance rebuild откладывает текущий batch и завершает проход; повтор безопасен.
 * Каждый вывод сообщает полноту истории projectors: после prune rebuild недоступен (history_unavailable),
 * поэтому архив не выдаёт себя за полный.
 */
#[Description('Пакетно удаляет устаревшие transition events с подтверждёнными projections (--dry-run только считает)')]
#[Signature('process:prune-history {--dry-run : только показать число кандидатов первого batch} {--max-batches=20 : максимальное число batch за запуск}')]
final class PruneHistoryCommand extends Command
{
    private const int TIME_BUDGET_NS = 30_000_000_000;

    public function handle(RetentionService $retention, ProjectionRegistry $registry, ProjectionRepository $projections, ProcessClock $clock): int
    {
        $maxBatches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($maxBatches === false) {
            $this->error('--max-batches должен быть положительным целым числом.');

            return self::INVALID;
        }

        $now = $clock->dbNow()->value;
        if ($this->option('dry-run')) {
            $this->info('Кандидатов в этом batch: '.count($retention->candidates($now)).'.');
            $this->reportCompleteness($registry, $projections);

            return self::SUCCESS;
        }

        $deadline = $clock->monotonicNs() + self::TIME_BUDGET_NS;
        $pruned = 0;
        $ineligible = 0;
        for ($batch = 0; $batch < $maxBatches && $clock->monotonicNs() < $deadline; $batch++) {
            $result = $retention->prune($now);
            if ($result->deferredForMaintenance) {
                $this->warn('Rebuild в maintenance: batch отложен, ничего не удалено.');
                if ($batch === 0) {
                    $this->reportCompleteness($registry, $projections);

                    return self::SUCCESS;
                }

                break;
            }
            $pruned += $result->pruned;
            // Неподтверждённые кандидаты остаются в начале очереди и могут повториться в следующем batch.
            $ineligible = $result->ineligible;
            if ($result->pruned === 0) {
                break;
            }
        }
        $this->info("Удалено {$pruned}, оставлено до подтверждения projections {$ineligible}.");
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
