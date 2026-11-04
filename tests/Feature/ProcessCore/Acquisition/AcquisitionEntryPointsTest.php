<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\User;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Procurement\Acquisition\AcquisitionTick;
use App\Services\Procurement\Acquisition\LaunchAcquisition;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('acquisition-operations', 'run-state-reduction');

beforeEach(function (): void {
    ListingFixtures::bind();
    $this->artisan('guard:sync-roles')->assertExitCode(0);
});

function entryProfile(AcquisitionKind $kind = AcquisitionKind::Fresh): Crawler
{
    $day = AcquisitionProfileFixtures::sourceDay();
    $route = ListingStoreRepository::id('entry-route')->value;
    DB::table('prc_network_route_profiles')->insertOrIgnore(['id' => $route, 'profile_key' => 'entry', 'policy_version' => 1]);
    $profile = EisCrawlerFactory::new()->create(['listing_kind' => $kind, 'route_profile_id' => $route,
        'filter' => new EisSearchFilter(okpd2Codes: ['43'], publishedFrom: $day, publishedTo: $day),
        'schedule' => '* * * * *']);
    if (! Okpd2Node::query()->where('platform_id', $profile->registry->platform_id)->where('code', '43')->exists()) {
        Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
    }

    return $profile;
}

test('real minute producer allocates all acquisition kinds once without dispatching old jobs', function (): void {
    foreach (AcquisitionKind::cases() as $kind) {
        entryProfile($kind);
    }
    app(AcquisitionTick::class)->tick();
    app(AcquisitionTick::class)->tick();
    expect(DB::table('prc_acquisition_schedules')->count())->toBe(3)
        ->and(DB::table('prc_process_runs')->where('kind', 'schedule')->count())->toBe(3)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(3)
        ->and(Schema::hasTable('prc_crawler_runs'))->toBeFalse();
});

test('empty and invalid cron do not launch while another profile progresses', function (): void {
    Log::spy();
    $empty = entryProfile();
    $empty->update(['schedule' => null]);
    $invalid = entryProfile();
    $invalid->update(['schedule' => 'invalid']);
    $valid = entryProfile();
    app(AcquisitionTick::class)->tick();
    expect(DB::table('prc_process_runs')->pluck('profile_id')->all())->toBe([$valid->id]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['profile_id'] === $invalid->id)->once();
});

test('operator launch is versioned deduplicated and rejects an unauthorized actor', function (): void {
    $profile = entryProfile();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $command = ListingStoreRepository::id('entry-command');
    $launch = app(LaunchAcquisition::class);
    expect(fn () => $launch->execute($analyst, $profile, 1, $command))->toThrow(AuthorizationException::class);
    $id = $launch->execute($operator, $profile, 1, $command);
    expect($launch->execute($operator, $profile, 1, $command)->value)->toBe($id->value)
        ->and(DB::table('prc_process_runs')->sole()->requested_by)->toBe($operator->id);
    $profile->update(['name' => 'Changed']);
    expect(fn () => $launch->execute($operator, $profile, 1, ListingStoreRepository::id('entry-command-2')))
        ->toThrow(InvalidArgumentException::class, 'Профиль изменился');
    expect(DB::table('prc_process_runs')->count())->toBe(1);
});

test('profile UI launch uses a signed version and durable command identity', function (): void {
    $profile = entryProfile();
    $actor = User::factory()->create();
    $actor->assignRole(OperatorRole::class);
    $this->actingAs($actor);
    $data = [OperatorActionInput::FIELD => OperatorActionInput::issue('launch_acquisition', (string) $profile->id, 1),
        'command_id' => ListingStoreRepository::id('entry-ui')->value];
    Livewire::test(ListCrawlers::class)->callTableAction('start', $profile, data: $data)->assertHasNoTableActionErrors();
    Livewire::test(ListCrawlers::class)->callTableAction('start', $profile, data: $data)->assertHasNoTableActionErrors();
    expect(DB::table('prc_process_runs')->count())->toBe(1);
    $data[OperatorActionInput::FIELD] = '1.invalid';
    $data['command_id'] = ListingStoreRepository::id('entry-ui-invalid')->value;
    Livewire::test(ListCrawlers::class)->callTableAction('start', $profile, data: $data);
    expect(DB::table('prc_process_runs')->count())->toBe(1);
});
