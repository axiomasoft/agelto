<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Platform\PlatformCode;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('process:status {--platform= : Registered platform code}')]
final class ProcessStatusCommand extends Command
{
    protected $description = 'Read platform coverage and replayable process metrics without executing work';

    public function handle(PlatformRegistry $platforms, PlatformMetricsReadRepository $metrics): int
    {
        $filter = $this->option('platform');
        try {
            $definitions = is_string($filter) && $filter !== ''
                ? [$platforms->get(new PlatformCode($filter))] : $platforms->all();
        } catch (\InvalidArgumentException $failure) {
            $this->error($failure->getMessage());

            return self::INVALID;
        }
        foreach ($definitions as $definition) {
            $snapshot = $metrics->read($definition->code);
            $this->info($definition->code->value.' measured_at='.$snapshot->measuredAt->value->format(DATE_ATOM));
            $control = $snapshot->acquisitionControl;
            $this->line('acquisition_control='.($control === null ? 'unknown' : $control->value)
                .($snapshot->pausedScopes === [] ? '' : ': '.implode(', ', $snapshot->pausedScopes)));
            $this->line('covered_through='.($snapshot->coveredThrough ?? 'unknown'));
            $this->line('acquisition_watermark_lag_seconds='.$snapshot->acquisitionWatermarkLag->label());
            $this->line('listing_closed_complete_ratio='.$snapshot->listingClosedCompleteRatio->label());
            $this->line('closed_partitions='.$snapshot->closedPartitions.' collecting_partitions='.$snapshot->collectingPartitions);
            $this->line('projection_lag_seconds='.$snapshot->projectionLag->label());
            $this->line('detail_due_lag_seconds='.$snapshot->detailDueLag->label());
            $this->line('status_refresh_due_lag_seconds='.$snapshot->statusRefreshDueLag->label());
            $projection = $snapshot->projection;
            $this->line('projection_mode='.($projection === null ? 'unknown' : $projection->mode->value)
                .' generation='.($projection === null ? 'unknown' : (string) $projection->activeGeneration));
            foreach ($snapshot->counters as $counter) {
                $this->line($counter->metricKey.' '.$counter->dimensionKey.' '.$counter->value);
            }
            if ($snapshot->counters === []) {
                $this->line('counters=unknown (no_data)');
            }
            foreach ($snapshot->workCounts as $work) {
                $this->line('work_'.$work->state->value.' stage='.$work->stage->value
                    .' reason='.($work->reason === null ? 'none' : $work->reason->value).' count='.$work->count);
            }
        }

        return self::SUCCESS;
    }
}
