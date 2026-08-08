<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\Contracts\HealthCheck;
use App\Services\Procurement\Health\HealthCheckResultFactory;
use Carbon\CarbonInterface;
use LogicException;

final readonly class EstimateFieldsCheck implements HealthCheck
{
    public function __construct(private HealthCheckResultFactory $results) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::EstimateFields;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $minSample = $this->minSample(baseline: $baseline);
        $estimates = Estimate::query()
            ->where('platform_id', $platform->id)
            ->whereBetween('updated_at', [$from, $to])
            ->with('items:id,estimate_id,code,name,unit,quantity')
            ->orderByDesc('updated_at')
            ->limit($minSample)
            ->get(['id', 'validation_status']);

        $sample = $estimates->count();
        $badEstimates = $estimates->filter(fn (Estimate $estimate): bool => in_array(
            $estimate->validation_status,
            [EstimateValidationStatus::Suspicious->value, EstimateValidationStatus::Invalid->value],
            strict: true,
        ))->count();
        $items = $estimates->flatMap->items;
        $badItems = $items->filter(fn ($item): bool => $item->code === null || $item->code === ''
            || $item->name === '' || $item->unit === null || $item->unit === '' || $item->quantity === null)->count();
        $estimateShare = $sample === 0 ? 0 : $badEstimates / $sample;
        $itemShare = $items->isEmpty() ? 0 : $badItems / $items->count();

        return $this->results->make(
            kind: $this->kind(),
            baseline: $baseline,
            measured: [
                'sample' => $sample,
                'validation_bad' => $badEstimates,
                'validation_share' => $estimateShare,
                'items_sample' => $items->count(),
                'items_bad' => $badItems,
                'items_share' => $itemShare,
            ],
            sampleSize: $sample,
            badShares: [$estimateShare, $itemShare],
        );
    }

    private function minSample(HealthBaseline $baseline): int
    {
        $minSample = $baseline->thresholds[$this->kind()->value]['min_sample'] ?? null;

        if (! is_int($minSample) || $minSample < 1) {
            throw new LogicException('В эталоне отсутствует положительный min_sample для estimate_fields.');
        }

        return $minSample;
    }
}
