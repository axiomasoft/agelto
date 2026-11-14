<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogRegistry;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\NetworkFixtureDefinition;
use Tests\Support\ProcessCore\NetworkFixtures;

beforeEach(function (): void {
    Http::preventStrayRequests();
    NetworkFixtures::platform();
});

test('повтор sync сохраняет origin aliases, overrides, debt и health epoch без нового audit', function (): void {
    $sync = app(SourceCatalogSync::class);
    $first = $sync->sync(NetworkFixtures::catalog());
    expect($first->targetsCreated)->toBe(2)
        ->and($first->budgetsCreated)->toBe(0)->and($first->healthRowsCreated)->toBe(0)
        ->and(DB::table('prc_network_budgets')->count())->toBe(0)
        ->and(DB::table('prc_target_health')->count())->toBe(0);
    HistoricalNetworkFixtures::synchronize();
    $scope = CanonicalOrigin::fromUrl('https://example.test')->budgetScopeKey();
    DB::table('prc_network_budgets')->where('scope_key', $scope)->update([
        'tat' => '2030-01-01T00:00:00Z', 'send_tat' => '2030-01-01T00:00:01Z', 'not_before' => '2030-01-01T00:00:02Z',
    ]);
    DB::table('prc_target_health')->update(['health_epoch' => 7, 'state' => 'open']);
    // Замороженный архив уже содержит операторский override; source sync не является network writer.
    DB::table('prc_network_budgets')->where('scope_key', $scope)->update([
        'emission_interval_us' => 5_000_000, 'burst' => 2, 'max_inflight' => 3, 'policy_version' => 2]);
    $before = DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all();
    $events = DB::table('prc_process_transitions')->count();
    $repeat = $sync->sync(NetworkFixtures::catalog());
    expect($repeat->changed())->toBeFalse()->and($repeat->driftedBudgets)->toBe([])
        ->and(DB::table('prc_network_budgets')->orderBy('scope_key')->get()->all())->toEqual($before)
        ->and(DB::table('prc_process_transitions')->count())->toBe($events)
        ->and(DB::table('prc_target_health')->pluck('health_epoch')->all())->toBe([7, 7])
        ->and(DB::table('prc_source_targets')->pluck('policy_version')->all())->toBe([1, 1]);
    expect(fn () => new OriginBudgetPolicy(0, 1, 2))->toThrow(InvalidArgumentException::class);
    expect(DB::table('prc_process_transitions')->count())->toBe($events);
});

test('target key принадлежит platform а quota общему canonical origin', function (): void {
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog());
    NetworkFixtures::platform('other');
    $sync->sync(NetworkFixtures::catalog('other'));
    expect(DB::table('prc_source_targets')->where('target_key', 'card')->count())->toBe(2)
        ->and(DB::table('prc_network_budgets')->count())->toBe(0);
    expect(fn () => $sync->sync(NetworkFixtures::catalog('other', new OriginBudgetPolicy(16, 1, 2))))->toThrow(InvalidArgumentException::class, 'Conflicting origin');
    expect(DB::table('prc_source_targets')->pluck('policy_version')->all())->toBe([1, 1, 1, 1]);
});

test('retire сохраняет tombstone и explicit sync не оживляет target', function (): void {
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog());
    $id = new ProcessUuid((string) DB::table('prc_source_targets')->where('target_key', 'card')->sole()->id);
    expect($sync->retireTarget($id, 1, null, 'fixture retire')->status)->toBe(NetworkChangeStatus::Applied);
    $repeat = $sync->sync(NetworkFixtures::catalog());
    expect($repeat->retiredDeclared)->toBe(['card'])->and($repeat->changed())->toBeFalse()
        ->and(DB::table('prc_source_targets')->where('id', $id->value)->sole()->policy_version)->toBe(2)
        ->and(DB::table('prc_source_targets')->where('id', $id->value)->sole()->retired_at)->not->toBeNull();
    $sync->sync(new SourceTargetDefinitions('fixture', []));
    expect(DB::table('prc_source_targets')->whereNotNull('retired_at')->count())->toBe(2);
});

test('config-only registry и dependency resolution не делают DB или HTTP I/O', function (): void {
    config()->set('platforms.definitions', ['fixture' => NetworkFixtureDefinition::class]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $policy = app(OutboundPolicy::class);
    $registry = app(SourceCatalogRegistry::class);
    app(SourceCatalogSync::class);
    expect(array_keys($registry->catalogs()))->toBe(['fixture'])
        ->and($policy->statusHourlyCap($policy->originBudget))->toBe(270)
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    test()->artisan('platform:sync-catalog')->assertSuccessful();
    expect(DB::table('prc_source_targets')->count())->toBe(2);
    Http::assertNothingSent();
});

pest()->group('source-catalog-lifecycle');
