<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Health\Contracts\HealthCheck;
use App\Services\Procurement\Health\HealthCheckResultFactory;
use Carbon\CarbonInterface;

final readonly class CardParseCheck implements HealthCheck
{
    public function __construct(private HealthCheckResultFactory $results) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::CardParse;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $procurements = Procurement::query()
            ->whereBetween('detail_fetched_at', [$from, $to])
            ->whereHas('registry', fn ($query) => $query->where('platform_id', $platform->id))
            ->get(['id', 'detail_parse_outcome']);

        $bad = 0;
        $knownBrokenTabs = 0;

        foreach ($procurements as $procurement) {
            $outcomes = $procurement->detail_parse_outcome;
            $outcomes = is_array($outcomes) ? $outcomes : [];
            $procurementIsBad = false;

            foreach ($baseline->cardTabs as $tab => $description) {
                if (($description['status'] ?? null) === 'known_broken') {
                    $knownBrokenTabs++;

                    continue;
                }

                $outcome = $outcomes[$tab] ?? null;

                if (! is_array($outcome)) {
                    $procurementIsBad = true;

                    continue;
                }

                $outcome = self::stringKeyedMap($outcome);

                if (($outcome['status'] ?? null) !== 'measured'
                    || self::nonNegativeInt($outcome, 'anchors_missing_critical') > 0
                    || (self::nonNegativeInt($outcome, 'key_fields_expected') > 0
                        && self::nonNegativeInt($outcome, 'key_fields_filled') === 0)
                ) {
                    $procurementIsBad = true;
                }
            }

            $bad += $procurementIsBad ? 1 : 0;
        }

        $sample = $procurements->count();
        $share = $sample === 0 ? 0 : $bad / $sample;

        return $this->results->make(
            kind: $this->kind(),
            baseline: $baseline,
            measured: ['sample' => $sample, 'bad' => $bad, 'share' => $share, 'known_broken_tabs_excluded' => $knownBrokenTabs],
            sampleSize: $sample,
            badShares: [$share],
        );
    }

    /** @param array<string, mixed> $outcome */
    private static function nonNegativeInt(array $outcome, string $key): int
    {
        $value = $outcome[$key] ?? 0;

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyedMap(array $value): array
    {
        $result = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }
}
