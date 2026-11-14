<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Dto\Procurement\Platform\DiagnosticInput;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Network\Contracts\DiagnosticCheck;
use App\Services\Procurement\Proxyma\ProfileResolver;

final readonly class DiagnosticRunner
{
    public function __construct(private AcquisitionProfiles $profiles, private AcquisitionDiagnostic $diagnostic,
        private ProfileResolver $routes) {}

    public function run(DiagnosticCheck $check, PlatformDefinition $definition, int $platformId,
        HealthBaseline $baseline, \DateTimeImmutable $at): CheckResult
    {
        $from = $at->modify('-1 day')->setTime(0, 0);
        $through = $from->setTime(23, 59, 59);
        $profile = Crawler::query()->where('listing_kind', AcquisitionKind::Fresh->value)
            ->where('is_active', true)->whereHas('registry', fn ($query) => $query->where('platform_id', $platformId))
            ->with('registry.platform')->orderBy('id')->first();
        $pages = [];
        $requests = 0;
        $unavailable = null;
        if ($profile !== null) {
            $launch = $this->profiles->preparer($profile)->prepare($profile, AcquisitionKind::Archive, $from, $through);
            $plan = $check->plan($launch);
            $settings = AcquisitionConfiguration::decode($launch->configuration);
            $routes = $this->routes->profile($settings->routeProfileId);
            foreach ($plan->requests as $ordinal => $request) {
                try {
                    $result = $this->diagnostic->fetch($definition, $platformId, $request, $routes,
                        ContentHash::of($check->kind()->value.':'.$ordinal.':'.$launch->filter->payloadHash->hex
                            .':'.$launch->plan->windows[0]->cursor->payloadHash->hex));
                } catch (OutboundUnavailable $failure) {
                    $unavailable = $failure->outcome->reason;
                    break;
                }
                $requests += $result->requestsUsed;
                if ($result->value instanceof ListingFetched) {
                    $pages[] = $result->value->page;
                    if (! $result->value->page->assessment->usable()) {
                        break;
                    }
                } else {
                    if ($result->value instanceof FetchDeferred
                        || $result->value instanceof FetchBlocked) {
                        $unavailable = $result->value->reason;
                    }
                    break;
                }
            }
        }

        return $check->run(new DiagnosticInput($baseline, $pages, $requests, $profile?->id, $from, $through, $unavailable));
    }
}
