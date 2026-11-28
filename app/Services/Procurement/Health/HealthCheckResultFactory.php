<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use LogicException;

/** Превращает измеренные доли одной проверки в единый вердикт по versioned baseline. */
final readonly class HealthCheckResultFactory
{
    /**
     * @param  array<string, mixed>  $measured
     * @param  list<float>  $badShares
     */
    public function make(
        PlatformCheckKind $kind,
        HealthBaseline $baseline,
        array $measured,
        int $sampleSize,
        array $badShares,
    ): CheckResult {
        $thresholds = $baseline->thresholds[$kind->value] ?? null;

        if (! is_array($thresholds)) {
            throw new LogicException("В эталоне отсутствуют пороги {$kind->value}.");
        }

        $minSample = $thresholds['min_sample'] ?? null;
        $degradedShare = $thresholds['degraded_share'] ?? null;
        $brokenShare = $thresholds['broken_share'] ?? null;

        if (! is_int($minSample) || ! is_numeric($degradedShare) || ! is_numeric($brokenShare)) {
            throw new LogicException("Пороги {$kind->value} не соответствуют контракту healthcheck.");
        }

        $worstShare = max($badShares ?: [0]);
        $verdict = match (true) {
            $sampleSize < $minSample => PlatformCheckVerdict::NotEnoughData,
            $worstShare >= (float) $brokenShare => PlatformCheckVerdict::Broken,
            $worstShare >= (float) $degradedShare => PlatformCheckVerdict::Degraded,
            default => PlatformCheckVerdict::Ok,
        };

        $measured['worst_share'] = $worstShare;
        $measured['thresholds'] = [
            'min_sample' => $minSample,
            'degraded_share' => (float) $degradedShare,
            'broken_share' => (float) $brokenShare,
        ];

        $calibrationNote = $baseline->calibrated ? '' : '; пороги некалиброваны';

        return new CheckResult(
            kind: $kind,
            verdict: $verdict,
            measured: $measured,
            sampleSize: $sampleSize,
            requestsUsed: 0,
            message: sprintf('%s: sample=%d, worst_share=%.3f%s.', $kind->value, $sampleSize, $worstShare, $calibrationNote),
        );
    }
}
