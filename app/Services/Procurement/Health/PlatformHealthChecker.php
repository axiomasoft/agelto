<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Events\Procurement\PlatformHealthChanged;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Health\PlatformCheckStoreRepository;
use App\Services\Procurement\Acquisition\DiagnosticRunner;
use App\Services\Procurement\Health\Checks\CardParseCheck;
use App\Services\Procurement\Health\Checks\EstimateFieldsCheck;
use App\Services\Procurement\Health\Checks\ListingShapeCheck;
use App\Services\Procurement\Network\Contracts\DiagnosticCheck;
use App\Services\Procurement\Platform\PlatformRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use LogicException;

/** Оркеструет пассивные проверки: эталон читается один раз, история пишется только репозиторием. */
final readonly class PlatformHealthChecker
{
    /** @var list<PassiveHealthCheck> */
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
        $baseline = $this->baselineReader->read(platform: $platform);

        $platformCode = $platform->getAttribute('code');

        if (! is_string($platformCode)) {
            throw new LogicException('У площадки отсутствует строковый code.');
        }

        if ($baseline->platform !== $platformCode) {
            throw new LogicException("Эталон {$baseline->platform} не соответствует площадке {$platformCode}.");
        }

        $results = [];

        $checks = $this->checks;
        $definition = app(PlatformRegistry::class)->get(new PlatformCode($platformCode));
        foreach ($definition->checks->implementations as $implementation) {
            $check = app($implementation);
            if (! $check instanceof DiagnosticCheck) {
                throw new LogicException('Registered platform diagnostic must implement DiagnosticCheck.');
            }
            $checks[] = $check;
        }
        foreach ($checks as $check) {
            if ($kind !== null && $check->kind() !== $kind) {
                continue;
            }

            $result = $check instanceof DiagnosticCheck
                ? app(DiagnosticRunner::class)->run(
                    $check, $definition, $platform->id, $baseline, \DateTimeImmutable::createFromInterface($to))
                : $check->run(platform: $platform, baseline: $baseline, from: $from, to: $to);

            if (! $dryRun) {
                $previous = $this->checksStore->latestFor(platform: $platform, kind: $result->kind);
                $checkedAt = Carbon::instance($to);
                $stored = $this->checksStore->store(
                    platform: $platform,
                    result: $result,
                    baselineSha256: $baseline->sha256,
                    windowFrom: Carbon::instance($from),
                    windowTo: $checkedAt,
                    checkedAt: $checkedAt,
                );

                if ($this->shouldDispatchHealthChanged(
                    previous: $previous,
                    result: $result,
                    checkedAt: $checkedAt,
                )) {
                    PlatformHealthChanged::dispatch(
                        platformId: $platform->id,
                        checkKind: $result->kind,
                        previousVerdict: $this->previousVerdict(check: $previous),
                        verdict: $result->verdict,
                        checkId: $stored->id,
                        message: $result->message,
                    );
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    private function shouldDispatchHealthChanged(
        ?PlatformCheck $previous,
        CheckResult $result,
        Carbon $checkedAt,
    ): bool {
        $previousVerdict = $this->previousVerdict(check: $previous);

        if ($this->isAlertable(verdict: $result->verdict)) {
            if ($previousVerdict === null || $result->verdict->isWorseThan($previousVerdict)) {
                return true;
            }

            return $previousVerdict === $result->verdict
                && $this->previousCheckedAt(check: $previous)
                    ->addHours($this->repeatAlertHours())
                    ->lte($checkedAt);
        }

        return $result->verdict === PlatformCheckVerdict::Ok
            && $previousVerdict !== null
            && $previousVerdict !== PlatformCheckVerdict::Ok;
    }

    private function isAlertable(PlatformCheckVerdict $verdict): bool
    {
        return in_array($verdict, [PlatformCheckVerdict::Degraded, PlatformCheckVerdict::Broken], strict: true);
    }

    private function repeatAlertHours(): int
    {
        $value = config('crawler.health.repeat_alert_hours');

        if (! is_int($value)) {
            throw new LogicException('crawler.health.repeat_alert_hours must be an integer.');
        }

        return $value;
    }

    private function previousVerdict(?PlatformCheck $check): ?PlatformCheckVerdict
    {
        $verdict = $check?->getAttribute('verdict');

        return $verdict instanceof PlatformCheckVerdict ? $verdict : null;
    }

    private function previousCheckedAt(?PlatformCheck $check): Carbon
    {
        $checkedAt = $check?->getAttribute('checked_at');

        return $checkedAt instanceof CarbonInterface ? Carbon::instance($checkedAt) : throw new LogicException(
            'Последняя проверка здоровья не содержит времени проверки.',
        );
    }
}
