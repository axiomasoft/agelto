<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\CheckResult;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Health\PlatformCheckStoreRepository;
use App\Services\Procurement\Health\Checks\CardParseCheck;
use App\Services\Procurement\Health\Checks\EstimateFieldsCheck;
use App\Services\Procurement\Health\Checks\ListingShapeCheck;
use App\Services\Procurement\Health\Contracts\HealthCheck;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use LogicException;

/** Оркеструет пассивные проверки: эталон читается один раз, история пишется только репозиторием. */
final readonly class PlatformHealthChecker
{
    /** @var list<HealthCheck> */
    private array $checks;

    public function __construct(
        private HealthBaselineReader $baselineReader,
        private PlatformCheckStoreRepository $checksStore,
        CardParseCheck $cardParseCheck,
        ListingShapeCheck $listingShapeCheck,
        EstimateFieldsCheck $estimateFieldsCheck,
    ) {
        $this->checks = [$cardParseCheck, $listingShapeCheck, $estimateFieldsCheck];
    }

    /**
     * @return list<CheckResult>
     */
    public function run(
        Platform $platform,
        CarbonInterface $from,
        CarbonInterface $to,
        ?PlatformCheckKind $kind = null,
        bool $dryRun = false,
    ): array {
        $baseline = $this->baselineReader->read();

        $platformCode = (string) $platform->getAttribute('code');

        if ($baseline->platform !== $platformCode) {
            throw new LogicException("Эталон {$baseline->platform} не соответствует площадке {$platformCode}.");
        }

        $results = [];

        foreach ($this->checks as $check) {
            if ($kind !== null && $check->kind() !== $kind) {
                continue;
            }

            $result = $check->run(platform: $platform, baseline: $baseline, from: $from, to: $to);

            if (! $dryRun) {
                $this->checksStore->store(
                    platform: $platform,
                    result: $result,
                    baselineSha256: $baseline->sha256,
                    windowFrom: Carbon::instance($from),
                    windowTo: Carbon::instance($to),
                    checkedAt: Carbon::instance($to),
                );
            }

            $results[] = $result;
        }

        return $results;
    }
}
