<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as F;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as Q;

test('EIS query preserves stages search morphology inclusive prices and classifier scope', function (): void {
    $profile = F::create();
    $profile->update(['filter' => new EisSearchFilter(stageCompleted: true, stageApplications: false,
        priceFrom: 100000, priceTo: 5000000, searchString: 'ремонт кровли', morphology: true, okpd2Codes: ['43'])]);
    $query = Q::query($profile, day: '2025-03-15');
    expect($query)->toMatchArray(['pc' => 'on', 'searchString' => 'ремонт кровли', 'morphology' => 'on',
        'priceFromGeneral' => '100000.00', 'priceToGeneral' => '5000000.00', 'okpd2Ids' => '123', 'okpd2IdsWithNested' => 'on',
        'from' => '1', 'to' => '500', 'sortBy' => 'UPDATE_DATE', 'sortDirection' => 'true'])
        ->not->toHaveKey('af')->not->toHaveKey('ca')->not->toHaveKey('pa');
});

test('EIS query omits unset optional filters and retains all required CSV columns', function (): void {
    $profile = F::create();
    $query = Q::query($profile);
    expect($query)->not->toHaveKey('searchString')->not->toHaveKey('morphology')->not->toHaveKey('af')
        ->and($query['fz44'])->toBe('on')->and($query)->not->toHaveKey('fz223');
    foreach (['registryNumberCsv', 'publishDateCsv', 'lastDateChangeCsv', 'maxContractPriceCsv', 'nameOrderCsv',
        'stepOrderPlacementCsv', 'currencyCodeCsv', 'methodOrderPurchaseCsv', 'purchaseNumbersCsv',
        'startDateRequestCsv', 'endDateRequestCsv', 'customerNameCsv'] as $column) {
        expect($query[$column])->toBe('on');
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('EIS query uses a bounded source day with inclusive pagination and kopeck prices', function (): void {
    $profile = F::create(AcquisitionKind::Archive);
    $query = Q::query($profile, day: '2024-03-15', firstRow: 501, lowCents: 10001, highCents: 20099);
    expect($query)->toMatchArray(['publishDateFrom' => '15.03.2024', 'publishDateTo' => '15.03.2024',
        'from' => '501', 'to' => '1000', 'priceFromGeneral' => '100.01', 'priceToGeneral' => '200.99', 'sortDirection' => 'true'])
        ->not->toHaveKey('updateDateFrom')->not->toHaveKey('updateDateTo');
});

test('EIS update query removes profile publication bounds without losing scope', function (): void {
    $profile = F::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(stageCompleted: true, okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'), publishedTo: CarbonImmutable::parse('2025-12-31'))]);
    $query = Q::query($profile, AcquisitionKind::Delta, '2026-08-08');
    expect($query)->toMatchArray(['updateDateFrom' => '08.08.2026', 'updateDateTo' => '08.08.2026', 'pc' => 'on', 'okpd2Ids' => '123'])
        ->not->toHaveKey('publishDateFrom')->not->toHaveKey('publishDateTo');
});

test('EIS publication query narrows the profile range to the requested source day', function (): void {
    $profile = F::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'), publishedTo: CarbonImmutable::parse('2025-12-31'))]);
    $query = Q::query($profile, day: '2025-06-15', lowCents: 10000, highCents: 20000);
    expect($query)->toMatchArray(['publishDateFrom' => '15.06.2025', 'publishDateTo' => '15.06.2025',
        'priceFromGeneral' => '100.00', 'priceToGeneral' => '200.00'])
        ->not->toHaveKey('updateDateFrom')->not->toHaveKey('updateDateTo');
});

test('EIS query rejects unsupported registry and unscoped profile before HTTP', function (): void {
    $profile = F::create();
    $profile->registry->update(['code' => 'fz223']);
    expect(fn () => Q::query($profile))->toThrow(InvalidArgumentException::class, 'Unsupported EIS profile registry');
    $profile->registry->update(['code' => 'fz44']);
    expect(fn () => $profile->update(['filter' => new EisSearchFilter]))
        ->toThrow(ValidationException::class, 'okpd2')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('EIS query resolves sorted classifier IDs independently of profile order and foreign platform', function (): void {
    $profile = F::create();
    $platformId = $profile->registry->platform_id;
    Okpd2Node::factory()->create(['platform_id' => $platformId, 'code' => '41', 'external_id' => '122']);
    Okpd2Node::factory()->create(['platform_id' => $platformId, 'code' => '42', 'external_id' => '124']);
    Okpd2Node::factory()->create(['platform_id' => Platform::factory()->create()->id, 'code' => '41', 'external_id' => '999']);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['41', '42', '43'])]);
    $a = Q::query($profile);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43', '42', '41'])]);
    $b = Q::query($profile);
    expect($a['okpd2Ids'])->toBe('122,123,124')->and($b['okpd2Ids'])->toBe($a['okpd2Ids'])
        ->and($b['okpd2IdsWithNested'])->toBe('on');
});

test('EIS query resolves a leaf classifier with nested descendants enabled', function (): void {
    $profile = F::create();
    Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43.21.10.140', 'external_id' => '9000001']);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43.21.10.140'])]);
    expect(Q::query($profile))->toMatchArray(['okpd2Ids' => '9000001', 'okpd2IdsWithNested' => 'on']);
});

test('EIS query rejects unresolved classifier before HTTP', function (): void {
    $profile = F::create();
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43', '99.99'])]);
    expect(fn () => Q::query($profile))->toThrow(InvalidArgumentException::class, 'unresolved OKPD2')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('EIS query pins declared sort field and always scans partition in ascending order', function (): void {
    $profile = F::create();
    foreach ([null => 'UPDATE_DATE', 'updated' => 'UPDATE_DATE', 'published' => 'PUBLISH_DATE'] as $field => $expected) {
        foreach ([false, true] as $ascending) {
            $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'],
                sortField: $field === '' ? null : ListingSortField::from($field), sortAscending: $ascending)]);
            expect(Q::query($profile))->toMatchArray(['sortBy' => $expected, 'sortDirection' => 'true']);
        }
    }
});

test('EIS query bounds fresh reads to a source day as well as archive reads', function (): void {
    $profile = F::create();
    $a = Q::query($profile, day: '2026-08-08');
    $b = Q::query($profile, day: '2026-08-08');
    expect($a)->toBe($b)->and($a)->toMatchArray(['publishDateFrom' => '08.08.2026', 'publishDateTo' => '08.08.2026',
        'sortDirection' => 'true', 'registryNumberCsv' => 'on']);
});
