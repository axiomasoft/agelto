<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Platform\AcquisitionProfileDescriptor;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\RecordingOutbound;

test('registered profile preparation pins scope price sort route and bounded archive continuation', function (): void {
    $route = ListingStoreRepository::id('profile-route')->value;
    DB::table('prc_network_route_profiles')->insert(['id' => $route, 'profile_key' => 'profile-test', 'policy_version' => 1]);
    $profile = EisCrawlerFactory::new()->create(['route_profile_id' => $route, 'filter' => new EisSearchFilter(
        stageCompleted: true, priceFrom: 12, priceTo: 750, searchString: 'ремонт', morphology: true,
        okpd2Codes: ['43'], sortField: ListingSortField::Published)]);
    Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
    $descriptor = app(PlatformRegistry::class)->get(new PlatformCode('eis'))->acquisitionProfile;
    expect($descriptor)->toBeInstanceOf(AcquisitionProfileDescriptor::class);
    $launch = app($descriptor->preparerClass)->prepare($profile, AcquisitionKind::Archive,
        new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-03-01'));
    $cursor = EisListingCursor::decode($launch->plan->windows[0]->cursor);
    $filter = (new EisListingPartitioner)->restoreFilter($launch->filter);
    expect($launch->plan->windows)->toHaveCount(400)->and($launch->plan->continuation)->not->toBeNull()
        ->and($cursor->lowCents)->toBe(1200)->and($cursor->highCents)->toBe(75000)
        ->and($filter->okpd2Ids)->toBe(['123'])->and($filter->stages)->toBe(['pc'])
        ->and($filter->morphology)->toBeTrue()->and($filter->sortBy)->toBe('PUBLISH_DATE')
        ->and(json_decode($launch->configuration->json, true)['route_profile_id'])->toBe($route);
    $source = new EisListingSource(new EisPolicy(config()),
        new RecordingOutbound, ListingStoreRepository::id('preview-target'));
    $request = new ListingRequest('fz44', $filter, $launch->plan->windows[0]->cursor);
    parse_str((string) parse_url($source->plan($request)->url->reveal(), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['okpd2Ids' => '123', 'okpd2IdsWithNested' => 'on',
        'registryNumberCsv' => 'on', 'publishDateCsv' => 'on', 'lastDateChangeCsv' => 'on']);
    $version = $launch->profileVersion;
    $profile->update(['name' => 'Updated profile']);
    expect((int) $profile->getAttribute('profile_version'))->toBe($version + 1);
});

test('unresolved reference and absent archive range cannot silently widen a profile', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $descriptor = app(PlatformRegistry::class)->get(new PlatformCode('eis'))->acquisitionProfile;
    $preparer = app($descriptor->preparerClass);
    expect(fn () => $preparer->prepare($profile, AcquisitionKind::Fresh))
        ->toThrow(InvalidArgumentException::class, 'unresolved');
    Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
    expect(fn () => $preparer->prepare($profile, AcquisitionKind::Archive))
        ->toThrow(InvalidArgumentException::class, 'start date');
});

test('profile descriptor rejects an implementation outside its typed contract', function (): void {
    expect(fn () => new AcquisitionProfileDescriptor(EisSearchFilter::class, stdClass::class, 'scope'))
        ->toThrow(InvalidPlatformDefinition::class);
})->group('platform-definition-boot');

pest()->group('acquisition-operations');
