<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\PlatformAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\NetworkFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('явные версии адаптера читаются без I/O и не зависят от deployment identity', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $ref = app(PlatformAdapterRegistry::class)->adapterContract('eis', 'listing');
    expect($ref->toArray())->toBe(['platform_code' => 'eis', 'capability' => 'listing', 'adapter_version' => '1', 'contract_version' => '1'])
        ->and(AdapterContractRef::fromArray(array_reverse($ref->toArray(), true))->equals($ref))->toBeTrue()
        ->and($ref->fingerprint()->hex)->toBe((new AdapterContractRef('eis', 'listing', '1', '1'))->fingerprint()->hex)
        ->and($queries)->toBe([]);
})->group('t04', 'p26');

test('binding отвергает другую capability площадку и provider owner', function (): void {
    $origin = CanonicalOrigin::fromUrl('https://example.test');
    $ref = new AdapterContractRef('fixture', 'listing', '1', '1');
    expect(fn () => new SourceTargetDefinition('card', 'detail', $origin, [NetworkFixtures::profile()], adapterContract: $ref))
        ->toThrow(InvalidArgumentException::class, 'another capability');
    $target = new SourceTargetDefinition('listing', 'listing', $origin, [NetworkFixtures::profile()], adapterContract: $ref);
    expect(fn () => new SourceTargetDefinitions('other', [$target]))->toThrow(InvalidArgumentException::class, 'another platform');
    $providerTarget = new SourceTargetDefinition('provider:fixture:listing', 'listing', $origin, [NetworkFixtures::profile()], adapterContract: $ref);
    expect(fn () => new ProviderTargetDefinitions(new ProviderKey('fixture'), [$providerTarget]))
        ->toThrow(InvalidArgumentException::class, 'platform parser contract');
})->group('t04', 'p26');

test('catalog создаёт validating contract один раз и новая версия не оживляет старый hold', function (): void {
    $code = 'adapter_'.bin2hex(random_bytes(6));
    NetworkFixtures::platform($code);
    $origin = CanonicalOrigin::fromUrl('https://example.test');
    $catalog = static fn (string $version): SourceTargetDefinitions => new SourceTargetDefinitions($code, [
        new SourceTargetDefinition('listing', 'listing', $origin, [NetworkFixtures::profile()],
            adapterContract: new AdapterContractRef($code, 'listing', $version, '1')),
    ]);
    $sync = app(SourceCatalogSync::class);
    $sync->sync($catalog('1'));
    $row = DB::table('prc_adapter_health')->sole();
    expect($row->state)->toBe('validating')->and($row->health_epoch)->toBe(1)
        ->and(DB::table('prc_source_targets')->sole()->endpoint_policy_schema_version)->toBe(2);
    DB::table('prc_adapter_health')->where('id', $row->id)->update(['state' => 'held', 'health_epoch' => 2, 'state_version' => 2, 'reason_code' => 'fixture_contract']);
    $before = DB::table('prc_adapter_health')->sole();
    expect($sync->sync($catalog('1'))->changed())->toBeFalse()
        ->and((array) DB::table('prc_adapter_health')->sole())->toBe((array) $before);
    $sync->sync($catalog('2'));
    expect(DB::table('prc_adapter_health')->where('adapter_version', '1')->sole()->state)->toBe('held')
        ->and(DB::table('prc_adapter_health')->where('adapter_version', '2')->sole()->state)->toBe('validating')
        ->and(DB::table('prc_adapter_health')->count())->toBe(2);
})->group('t04', 'p26');
