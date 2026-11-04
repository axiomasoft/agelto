<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\NetworkFixtures;

function providerOwnershipCatalog(string $key = 'fixture', int $rate = 15): ProviderTargetDefinitions
{
    return new ProviderTargetDefinitions(new ProviderKey($key), [
        new SourceTargetDefinition('provider:'.$key.':generate', 'provider', CanonicalOrigin::fromUrl('https://example.test'),
            [NetworkFixtures::profile()], new OriginBudgetPolicy($rate, 1, 2)),
    ]);
}

test('provider identity rejects invalid keys and foreign target ownership', function (): void {
    foreach (['', 'Upper', '../fixture', 'fixture key', str_repeat('x', 97)] as $key) {
        expect(fn () => new ProviderKey($key))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new ProviderTargetDefinitions(new ProviderKey('a'), array_values(providerOwnershipCatalog('b')->targets)))
        ->toThrow(InvalidArgumentException::class);
})->group('network-route-policy');

test('provider sync shares platform origin debt and preserves isolated owner catalogs', function (): void {
    NetworkFixtures::platform();
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog());
    $origin = CanonicalOrigin::fromUrl('https://example.test')->budgetScopeKey();
    DB::table('prc_network_budgets')->where('scope_key', $origin)->update(['tat' => '2030-01-01T00:00:00Z']);
    $first = $sync->syncProvider(providerOwnershipCatalog());
    expect($first->targetsCreated)->toBe(1)->and($first->platformCode)->toBeNull()
        ->and($first->providerKey?->value)->toBe('fixture')
        ->and(DB::table('prc_source_targets')->whereNull('platform_id')->where('provider_key', 'fixture')->count())->toBe(1)
        ->and(DB::table('prc_network_budgets')->where('scope_key', $origin)->count())->toBe(1)
        ->and(DB::table('prc_network_budgets')->where('scope_key', $origin)->value('tat'))->toContain('2030-01-01');
    $before = DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all();
    expect($sync->syncProvider(providerOwnershipCatalog())->changed())->toBeFalse()
        ->and(DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all())->toEqual($before);
    $sync->syncProvider(providerOwnershipCatalog('other'));
    $sync->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture'), []));
    expect(DB::table('prc_source_targets')->where('provider_key', 'fixture')->whereNotNull('retired_at')->count())->toBe(1)
        ->and(DB::table('prc_source_targets')->where('provider_key', 'other')->whereNull('retired_at')->count())->toBe(1)
        ->and(DB::table('prc_source_targets')->whereNotNull('platform_id')->whereNull('retired_at')->count())->toBe(2);
})->group('network-route-policy');

test('cross-owner conflicting origin defaults are rejected in both sync directions', function (): void {
    NetworkFixtures::platform();
    $sync = app(SourceCatalogSync::class);
    $sync->syncProvider(providerOwnershipCatalog('fixture', 14));
    expect(fn () => $sync->sync(NetworkFixtures::catalog()))->toThrow(InvalidArgumentException::class, 'Conflicting origin');
    expect(DB::table('prc_source_targets')->whereNotNull('platform_id')->count())->toBe(0);
})->group('network-route-policy');

test('provider target database constraints reject half ownership, bad keys and duplicates', function (string $invalid): void {
    $platform = NetworkFixtures::platform();
    $sync = app(SourceCatalogSync::class);
    $catalog = providerOwnershipCatalog();
    $sync->syncProvider(new ProviderTargetDefinitions($catalog->providerKey, [
        ...array_values($catalog->targets),
        new SourceTargetDefinition('provider:fixture:second', 'provider', CanonicalOrigin::fromUrl('https://example.test'),
            [NetworkFixtures::profile()], new OriginBudgetPolicy(15, 1, 2)),
    ]));
    $query = DB::table('prc_source_targets')->where('provider_key', 'fixture')->where('target_key', 'provider:fixture:second');
    $mutation = match ($invalid) {
        'both' => ['platform_id' => $platform->id],
        'neither' => ['provider_key' => null],
        'grammar' => ['provider_key' => 'Bad Key'],
        'duplicate' => ['target_key' => 'provider:fixture:generate'],
    };
    try {
        DB::transaction(fn () => $query->update($mutation));
        test()->fail('Database accepted invalid provider ownership.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe($invalid === 'duplicate' ? '23505' : '23514');
    }
    expect(DB::table('prc_source_targets')->where('provider_key', 'fixture')->whereNull('platform_id')->count())->toBe(2);
})->with(['both', 'neither', 'grammar', 'duplicate'])->group('network-route-policy');

test('provider scope uses canonical operator controls and audit without a procurement platform', function (): void {
    $platformsBefore = DB::table('prc_platforms')->count();
    app(SourceCatalogSync::class)->syncProvider(providerOwnershipCatalog());
    $healthBefore = DB::table('prc_target_health')->orderBy('id')->get()->all();
    \Pest\Laravel\artisan('guard:sync-roles')->assertExitCode(0);
    $user = User::factory()->create(['email' => 'operator@agelto.ru']);
    $user->assignRole(OperatorRole::class);
    \Pest\Laravel\actingAs($user);
    $scope = ControlScope::query()->where('scope_key', 'provider:fixture')->sole();
    expect($scope->desired_mode->value)->toBe('enabled');
    foreach (['pause' => 'paused', 'resume' => 'enabled'] as $action => $mode) {
        Livewire::test(ListControlScopes::class)
            ->searchTable('provider:fixture')
            ->callTableAction($action, $scope, data: ['reason' => 'fixture provider control',
                OperatorActionInput::FIELD => OperatorActionInput::issue($action, $scope->id, $scope->control_version)])
            ->assertHasNoTableActionErrors();
        expect($scope->refresh()->desired_mode->value)->toBe($mode);
    }
    expect(DB::table('prc_process_transitions')->where('actor_id', $user->id)->count())->toBe(2)
        ->and(DB::table('prc_target_health')->orderBy('id')->get()->all())->toEqual($healthBefore)
        ->and(DB::table('prc_platforms')->count())->toBe($platformsBefore);
})->group('network-route-policy');
