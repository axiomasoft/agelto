<?php

declare(strict_types=1);

use App\Actions\Procurement\Network\UpdateRouteProfile;
use App\Filament\Pages\NetworkRouteProfilesPage;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    LegacyBusinessNetwork::install(fakeResponses: false);
});

function crawlerProxySettingsOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

test('оператор сохраняет nullable default только через action и видит безопасную подпись', function (): void {
    $operator = crawlerProxySettingsOperator();
    actingAs($operator);
    $proxy = Proxy::factory()->create(['username' => 'private-user']);
    $page = Livewire::test(NetworkRouteProfilesPage::class)
        ->fillForm(['profile_key' => 'default', 'route_ids' => [$proxy->route_id], 'reason' => 'Выбран маршрут'])
        ->call('save')->assertHasNoFormErrors()->assertNotified('Профиль маршрутов сохранён')
        ->assertDontSee('private-user');
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole();
    expect(DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->pluck('route_id')->all())->toBe([$proxy->route_id])
        ->and(DB::table('prc_process_transitions')->where('actor_id', $operator->id)->count())->toBe(1);
    $page->fillForm(['route_ids' => [], 'reason' => 'Остановлен маршрут'])->call('save')->assertHasNoFormErrors();
    expect(DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->count())->toBe(0);
});

test('страница исключает неактивные и неполные default-прокси, а action повторно отвергает их', function (): void {
    $operator = crawlerProxySettingsOperator();
    actingAs($operator);
    $proxy = Proxy::factory()->create();
    // Exact membership may retain disabled routes for configuration; it never creates send authority.
    $page = Livewire::test(NetworkRouteProfilesPage::class);
    $version = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->value('policy_version') ?? 0;
    app(UpdateRouteProfile::class)->execute($operator, 'default', $version, [$proxy->route_id], 'Другой оператор');
    $page->fillForm(['route_ids' => [], 'reason' => 'Устаревшая форма'])->call('save')
        ->assertNotified('Профиль изменён другим оператором. Перезагрузите данные.');
    expect(DB::table('prc_network_route_profiles')->where('profile_key', 'default')->value('policy_version'))->toBe($version + 1);
    expect(fn () => app(UpdateRouteProfile::class)->execute($operator, 'default', $version + 1, ['00000000-0000-4000-8000-000000000000'], 'Нет узла'))
        ->toThrow(InvalidArgumentException::class, 'member does not exist');
    expect(DB::table('prc_network_route_profile_members')->where('profile_id', DB::table('prc_network_route_profiles')->where('profile_key', 'default')->value('id'))->pluck('route_id')->all())->toBe([$proxy->route_id]);
});

test('страница скрыта и закрыта прямым URL без ProxyPermission Update', function (): void {
    actingAs(User::factory()->create());
    expect(NetworkRouteProfilesPage::canAccess())->toBeFalse();
    get(NetworkRouteProfilesPage::getUrl())->assertForbidden();
});
