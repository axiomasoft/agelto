<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Services\Procurement\Network\SourceCatalogRegistry;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\NetworkFixtures;

pest()->group('source-catalog-lifecycle');

beforeEach(function (): void {
    Http::preventStrayRequests();
    NetworkFixtures::platform('eis');
    NetworkFixtures::platform('json');
    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
});

test('actual Definitions drive CLI sync and preserve target identities adapter bindings and idempotency', function (): void {
    $catalogs = app(SourceCatalogRegistry::class)->catalogs();
    expect(array_keys($catalogs))->toBe(['eis', 'json']);
    test()->artisan('platform:sync-catalog', ['--platform' => ['eis', 'json']])->assertSuccessful();
    foreach ($catalogs as $code => $catalog) {
        $platformId = DB::table('prc_platforms')->where('code', $code)->value('id');
        foreach ($catalog->targets as $target) {
            $row = DB::table('prc_source_targets')->where('platform_id', $platformId)->where('target_key', $target->key)->sole();
            expect($row->canonical_origin)->toBe($target->origin->value())
                ->and($row->capability)->toBe($target->capability)->and($row->retired_at)->toBeNull();
        }
    }
    expect($catalogs['eis']->targets['csv']->adapterContract->capability)->toBe('listing')
        ->and($catalogs['eis']->targets['card']->adapterContract->capability)->toBe('detail')
        ->and($catalogs['eis']->targets['okpd2']->capability)->toBe('reference_sync');
    $origin = CanonicalOrigin::fromUrl('https://zakupki.gov.ru');
    expect(isset($catalogs['eis']->targets['document.'.substr($origin->hash()->hex, 0, 16)]))->toBeTrue();
    $sync = app(SourceCatalogSync::class);
    $before = DB::table('prc_source_targets')->orderBy('id')->get()->all();
    $events = DB::table('prc_process_transitions')->count();
    foreach ($sync->syncRegistered() as $report) {
        expect($report->changed())->toBeFalse();
    }
    expect(DB::table('prc_source_targets')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('prc_process_transitions')->count())->toBe($events);
    Http::assertNothingSent();
});

test('platform catalogs cannot fall back to the obsolete outbound provider registration', function (): void {
    config()->set('outbound.catalog_providers', [NetworkFixtures::class]);
    expect(fn () => app(SourceCatalogRegistry::class)->providers())->toThrow(InvalidArgumentException::class, 'platforms.definitions');
    config()->set('platforms.definitions', []);
    expect(app(SourceCatalogRegistry::class)->catalogs())->toBe([]);
    expect(fn () => app(SourceCatalogSync::class)->syncRegistered(['eis']))->toThrow(InvalidArgumentException::class, 'No source catalog');
    expect(DB::table('prc_source_targets')->count())->toBe(0);
});
