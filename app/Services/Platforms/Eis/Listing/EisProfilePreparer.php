<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Listing;

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\Control\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\Listing\ListingPlan;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\Contracts\AcquisitionProfilePreparer;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;

/** App integration role: reference catalogue reads precede the pure partition plan. */
final readonly class EisProfilePreparer implements AcquisitionProfilePreparer
{
    public function __construct(private EisPolicy $policy, private Okpd2NodeReadRepository $nodes,
        private ListingCoverageRepository $coverage, private OutboundPolicy $outbound) {}

    public function prepare(Crawler $profile, AcquisitionKind $kind, ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $through = null): AcquisitionLaunch
    {
        $filter = $profile->filter;
        if (! $filter instanceof EisSearchFilter || ! $filter->hasScope()) {
            throw new \InvalidArgumentException('A scoped EIS profile is required.');
        }
        $registry = $profile->registry ?? throw new \InvalidArgumentException('Profile registry is missing.');
        if ($registry->code !== 'fz44' || $registry->platform?->getAttribute('code') !== 'eis') {
            throw new \InvalidArgumentException('Unsupported EIS profile registry.');
        }
        $ids = $this->nodes->externalIds($registry->platform_id, $filter->okpd2Codes);
        if (array_diff($filter->okpd2Codes, array_keys($ids)) !== []) {
            throw new \InvalidArgumentException('Profile contains unresolved OKPD2 codes.');
        }
        $stages = [];
        foreach (['af' => $filter->stageApplications, 'ca' => $filter->stageCommission,
            'pc' => $filter->stageCompleted, 'pa' => $filter->stageCancelled] as $stage => $enabled) {
            if ($enabled) {
                $stages[] = $stage;
            }
        }
        $typed = new EisListingFilter(array_values($ids), $stages, $filter->searchString,
            $filter->morphology, $filter->sortField === ListingSortField::Published ? 'PUBLISH_DATE' : 'UPDATE_DATE');
        $clock = LedgerRow::one('SELECT clock_timestamp()::text AS now') ?? throw new \LogicException('Missing DB clock.');
        $now = new \DateTimeImmutable($clock->string('now'));
        $initial = $from ?? $filter->publishedFrom?->toDateTimeImmutable();
        if ($kind === AcquisitionKind::Archive && $initial === null) {
            throw new \InvalidArgumentException('Archive range requires a start date.');
        }
        $initial ??= $profile->created_at->toDateTimeImmutable();
        $end = $through ?? ($kind === AcquisitionKind::Archive ? $filter->publishedTo?->toDateTimeImmutable() : null) ?? $now;
        $start = $initial->setTimezone($this->policy->sourceTimezone)->format('Y-m-d');
        $finish = $end->setTimezone($this->policy->sourceTimezone)->format('Y-m-d');
        $route = app(ProfileResolver::class)->snapshot($profile->route_profile_id === null ? null : new ProcessUuid($profile->route_profile_id));
        if ($route === null) {
            throw new \InvalidArgumentException('Configured route profile is absent.');
        }
        $stageProfiles = [];
        if ($profile->route_profile_id === null || $route->string('profile_key') === $this->outbound->defaultRouteProfile) {
            foreach ($this->policy->stageRouteProfiles as $stage => $profileKey) {
                $stageRow = app(ProfileResolver::class)->snapshot(key: $profileKey);
                if ($stageRow !== null) {
                    $stageProfiles[$stage] = [
                        'route_profile_id' => $stageRow->string('id'),
                        'route_policy_version' => $stageRow->int('policy_version'),
                    ];
                }
            }
        }
        $routeId = $stageProfiles['listing']['route_profile_id'] ?? $route->string('id');
        $routeVersion = $stageProfiles['listing']['route_policy_version'] ?? $route->int('policy_version');
        $access = ContentHash::of(json_encode([$routeId, $routeVersion], JSON_THROW_ON_ERROR));
        $partitioner = new EisListingPartitioner($this->policy->listing, $this->policy->sourceTimezone);
        $axis = $kind === AcquisitionKind::Delta || ($kind === AcquisitionKind::Fresh && $filter->sortField === ListingSortField::Updated)
            ? 'update' : 'publish';
        $low = (string) ($filter->priceFrom ?? 0);
        $high = $filter->priceTo === null ? null : (string) $filter->priceTo;
        $first = $partitioner->plan($typed, $start, $start, $axis, $low, $high, $this->policy->blockSize);
        $stream = $partitioner->streamKey($registry->id, $first->windows[0]->cursor, $access, '1');
        if ($kind !== AcquisitionKind::Archive && $from === null) {
            $resumed = $this->coverage->read($stream)->resumeFrom($start);
            $start = $start <= $finish ? min($finish, $resumed) : $resumed;
        }
        $plan = $partitioner->plan($typed, $start, $finish, $axis, $low, $high, $this->policy->blockSize);
        $version = $profile->profile_version;
        $payloadData = [
            'profile_version' => $version,
            'route_profile_id' => $routeId,
            'route_policy_version' => $routeVersion,
            'kind' => $kind->value,
            'initial_from' => $initial->format(DATE_ATOM),
            'through' => $end->format(DATE_ATOM),
            'requested_from' => $from?->format(DATE_ATOM),
            'requested_through' => $through?->format(DATE_ATOM),
        ];
        if ($stageProfiles !== []) {
            $payloadData['stage_route_profiles'] = $stageProfiles;
        }

        return new AcquisitionLaunch($profile->id, $version, $registry->platform_id, $registry->id, $registry->code,
            $kind, '1', $partitioner->snapshot($typed), $access, $stream, $plan,
            new VersionedPayload(json_encode($payloadData, JSON_THROW_ON_ERROR), $stageProfiles === [] ? 2 : 3));
    }

    public function recoveryPlan(ListingTask $task): ListingPlan
    {
        return (new EisListingPartitioner($this->policy->listing, $this->policy->sourceTimezone))->recoveryPlan($task);
    }
}
