<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\Listing\EisProfilePreparer;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Carbon\CarbonImmutable;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Eis\EisContractManifest;

final class AcquisitionProfileFixtures
{
    public static function sourceDay(): CarbonImmutable
    {
        $clock = LedgerRow::one('SELECT clock_timestamp()::text AS now') ?? throw new \LogicException('Missing DB clock.');

        return CarbonImmutable::parse($clock->string('now'))->setTimezone(app(EisPolicy::class)->sourceTimezone)->startOfDay();
    }

    /** Exercise the actual preparer and request planner without network effects.
     * @return array<string, string>
     */
    public static function query(Crawler $profile, ?AcquisitionKind $kind = null, ?string $day = null,
        int $firstRow = 1, ?int $lowCents = null, ?int $highCents = null): array
    {
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)
            ->ensureProfile(app(OutboundPolicy::class)->defaultRouteProfile));
        $kind ??= $profile->listing_kind;
        $date = $day === null ? null : new \DateTimeImmutable($day);
        $launch = app(EisProfilePreparer::class)->prepare($profile, $kind, $date, $date);
        $filter = (new EisListingPartitioner)->restoreFilter($launch->filter);
        $base = EisListingCursor::decode($launch->plan->windows[0]->cursor);
        $cursor = new EisListingCursor($base->day, $base->dateField, $lowCents ?? $base->lowCents,
            $highCents ?? $base->highCents, $filter->fingerprint(), $firstRow, blockSize: $base->blockSize);
        $plan = EisContractManifest::listing()->plan(new ListingRequest($launch->registry, $filter, $cursor->envelope(), kind: $kind));
        parse_str((string) parse_url($plan->url->reveal(), PHP_URL_QUERY), $query);
        $strings = [];
        foreach ($query as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new \LogicException('Expected flat EIS query parameters.');
            }
            $strings[$key] = $value;
        }

        return $strings;
    }

    public static function create(AcquisitionKind $kind = AcquisitionKind::Fresh): Crawler
    {
        $day = self::sourceDay();
        $route = ListingStoreRepository::id('p218-command-route')->value;
        DB::table('prc_network_route_profiles')->insertOrIgnore(['id' => $route, 'profile_key' => 'p218-command', 'policy_version' => 1]);
        $profile = EisCrawlerFactory::new()->create(['listing_kind' => $kind, 'route_profile_id' => $route,
            'filter' => new EisSearchFilter(okpd2Codes: ['43'], publishedFrom: $day, publishedTo: $day),
            'schedule' => '* * * * *']);
        if (! $profile instanceof Crawler || $profile->registry === null) {
            throw new \LogicException('Expected one acquisition profile with a registry.');
        }
        if (! Okpd2Node::query()->where('platform_id', $profile->registry->platform_id)->where('code', '43')->exists()) {
            Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
        }

        return $profile;
    }

    /** @return list<EisListingCursor> */
    public static function cursors(Crawler $profile): array
    {
        $payloads = DB::table('prc_crawl_blocks as b')->join('prc_crawl_runs as c', 'c.id', '=', 'b.crawl_run_id')
            ->join('prc_process_runs as r', 'r.id', '=', 'c.process_run_id')->where('r.profile_id', $profile->id)
            ->orderBy('b.id')->pluck('b.partition_payload');
        $cursors = [];
        foreach ($payloads as $payload) {
            if (! is_string($payload)) {
                throw new \LogicException('Expected a serialized listing task.');
            }
            $cursors[] = EisListingCursor::decode(ListingTask::fromJson($payload)->cursor);
        }

        return $cursors;
    }

    public static function workSnapshot(string $workId): string
    {
        // PostgreSQL JSON includes bytea contents, not connection-local stream handles.
        $snapshot = DB::table('prc_process_work as w')->where('id', $workId)->selectRaw('to_jsonb(w)::text as snapshot')->value('snapshot');
        if (! is_string($snapshot)) {
            throw new \LogicException('Expected an existing work snapshot.');
        }

        return $snapshot;
    }
}
