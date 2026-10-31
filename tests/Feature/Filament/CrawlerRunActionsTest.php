<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\User;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\LaunchAcquisition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as L;

beforeEach(function (): void {
    L::bind();
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $this->operator = User::factory()->create();
    $this->operator->assignRole('operator');
    $this->actingAs($this->operator);
});

function launchUiProfile(Crawler $profile, ?string $command = null): void
{
    Livewire::test(ListCrawlers::class)->callTableAction('start', $profile, [
        OperatorActionInput::FIELD => OperatorActionInput::issue('launch_acquisition', (string) $profile->id, $profile->profile_version),
        'command_id' => $command ?? LedgerFixtures::uuid()->value,
    ])->assertHasNoTableActionErrors();
}

test('Process launch remains visible with active work and distinct commands create independent runs', function (): void {
    $profile = F::create();
    $command = LedgerFixtures::uuid()->value;
    launchUiProfile($profile, $command);
    Livewire::test(ListCrawlers::class)->assertTableActionVisible('start', $profile);
    launchUiProfile($profile, $command);
    expect(DB::table('prc_process_runs')->count())->toBe(1);
    $first = DB::table('prc_process_work')->sole();
    $snapshot = F::workSnapshot($first->id);
    launchUiProfile($profile);
    expect(DB::table('prc_process_runs')->count())->toBe(2)
        ->and(F::workSnapshot($first->id))->toBe($snapshot)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(2)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('Process launch gateway enforces actor and profile version without a second execution owner', function (): void {
    $profile = F::create();
    $analyst = User::factory()->create();
    $analyst->assignRole('analyst');
    expect(fn () => app(LaunchAcquisition::class)->execute($analyst, $profile, $profile->profile_version, LedgerFixtures::uuid()))
        ->toThrow(AuthorizationException::class);
    $version = $profile->profile_version;
    $profile->update(['name' => 'Edited']);
    expect(fn () => app(LaunchAcquisition::class)->execute($this->operator, $profile, $version, LedgerFixtures::uuid()))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('prc_process_runs')->count())->toBe(0);
});

test('archive UI launch pins every profile day and replay preserves the existing work', function (): void {
    $profile = F::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'), publishedTo: CarbonImmutable::parse('2025-01-03'))]);
    $command = LedgerFixtures::uuid()->value;
    launchUiProfile($profile, $command);
    $before = DB::table('prc_process_work')->orderBy('id')->pluck('id')->all();
    launchUiProfile($profile, $command);
    expect(F::cursors($profile))->toHaveCount(3)
        ->and(array_map(fn ($cursor) => $cursor->day, F::cursors($profile)))->toEqualCanonicalizing(['2025-01-01', '2025-01-02', '2025-01-03'])
        ->and(DB::table('prc_process_work')->orderBy('id')->pluck('id')->all())->toBe($before)
        ->and(DB::table('prc_process_runs')->count())->toBe(1);
});

test('archive UI launch rejects an absent range before creating a run', function (): void {
    $profile = F::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'])]);
    launchUiProfile($profile);
    expect(DB::table('prc_process_runs')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('Process UI pause resume preserves work checkpoint and audits both scope versions', function (): void {
    $f = L::run();
    $before = F::workSnapshot($f['work']->value->value);
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    $version = $scope->control_version;
    Livewire::test(ListControlScopes::class)->assertTableActionVisible('pause', $scope)
        ->callTableAction('pause', $scope, ['reason' => 'inspect source'])->assertHasNoTableActionErrors();
    expect($scope->refresh()->desired_mode->value)->toBe('paused');
    Livewire::test(ListControlScopes::class)->assertTableActionVisible('resume', $scope)
        ->callTableAction('resume', $scope, ['reason' => 'source inspected'])->assertHasNoTableActionErrors();
    expect($scope->refresh()->desired_mode->value)->toBe('enabled')->and($scope->control_version)->toBe($version + 2)
        ->and(F::workSnapshot($f['work']->value->value))->toBe($before)
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->where('actor_id', $this->operator->id)->count())->toBe(2);
});

test('Process UI cancel is audited idempotent and keeps another runs required work', function (): void {
    $f = L::run();
    $other = L::run(registry: Registry::query()->sole());
    app(WorkStoreRepository::class)->linkRun($other['run'], $f['work'], RunWorkRelation::Discovered, AccountingRole::Required);
    $run = ProcessRun::query()->findOrFail($f['run']->value);
    Livewire::test(ListProcessRuns::class)->callTableAction('cancel_run', $run, ['reason' => 'cancel this intent'])
        ->assertHasNoTableActionErrors();
    $version = $run->refresh()->transition_seq;
    Livewire::test(ListProcessRuns::class)->assertTableActionHidden('cancel_run', $run);
    expect($run->cancel_requested_at)->not->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $other['run']->value)->value('cancel_requested_at'))->toBeNull()
        ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('state'))->toBe('ready')
        ->and(DB::table('prc_process_transitions')->where('run_id', $f['run']->value)->where('actor_id', $this->operator->id)->count())->toBe(1)
        ->and($run->refresh()->transition_seq)->toBe($version);
});

test('Process UI terminal runs hide cancel and an analyst cannot launch or mutate controls', function (): void {
    $f = L::run();
    L::finish($f['work'], $f['input'], L::page());
    $pass = L::next($f['crawl']) ?? throw new LogicException('Missing pass');
    L::finish($pass['work'], $pass['input'], L::page());
    $run = ProcessRun::query()->findOrFail($f['run']->value);
    Livewire::test(ListProcessRuns::class)->assertTableActionHidden('cancel_run', $run);
    $analyst = User::factory()->create();
    $analyst->assignRole('analyst');
    $this->actingAs($analyst);
    $profile = F::create();
    $this->get(CrawlerResource::getUrl())->assertForbidden();
    expect(fn () => app(LaunchAcquisition::class)->execute($analyst, $profile, $profile->profile_version, LedgerFixtures::uuid()))
        ->toThrow(AuthorizationException::class);
    $this->get(ControlScopeResource::getUrl())->assertForbidden();
    $this->get(ProcessRunResource::getUrl())->assertOk();
    $active = L::run(registry: Registry::query()->firstOrFail());
    Livewire::test(ListProcessRuns::class)->assertTableActionHidden('cancel_run',
        ProcessRun::query()->findOrFail($active['run']->value));
});
