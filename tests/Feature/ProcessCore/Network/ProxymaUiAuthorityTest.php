<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\Control\AcquisitionConfiguration;
use App\Dto\Procurement\Crawler\PlatformThrottleSettings;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\User;
use App\Providers\ProxymaServiceProvider;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderTransportOutcome;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\Caller\CallerGate as ScheduledAttemptGate;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound as OutboundExecutor;
use App\Services\Procurement\Proxyma\Caller\RuntimeCaller;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver as ProxyResolver;
use App\Services\Procurement\Proxyma\Panel\AgeltoAuthorizer;
use App\Services\Procurement\Proxyma\Panel\AgeltoTenantContext;
use App\Services\Procurement\Proxyma\Panel\SettingsSections;
use App\Services\Procurement\Proxyma\Panel\UiAuthority;
use App\Services\Procurement\Proxyma\Panel\UiAuthorizer;
use App\Services\Procurement\Proxyma\Panel\UiTenantContext;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Filament\Schemas\Components\Actions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\BindingQueries;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\Actions\SaveCatalog;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Resources\Pages\Endpoints;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\ProcessCore\ProxymaProviderFixtures;

beforeEach(function (): void {
    ProxymaFixtures::clean();
    Artisan::call('guard:sync-roles');
});
afterEach(fn () => ProxymaFixtures::clean());

/** @return array{user: User, profile: string, route: string, legacy: string} */
function proxymaUiFixture(): array
{
    $wire = new WireFixture;
    try {
        $legacy = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        expect($wire->receipts())->toBe([]);
    } finally {
        $wire->stop();
    }
    $user = User::factory()->create();
    $user->assignRole(OperatorRole::class);
    test()->actingAs($user);
    app()->bind(Authorizer::class, UiAuthorizer::class);
    app()->bind(TenantContextResolver::class, UiTenantContext::class);
    app()->bind(CatalogMutationFence::class, UiAuthority::class);
    $profile = DB::table(PackageSchema::tables()['route_profiles'])->where('profile_key', config('outbound.routing.default_profile'))->sole();

    return ['user' => $user, 'profile' => $profile->id, 'route' => $legacy->route->routeId->value,
        'legacy' => config('outbound.routing.default_profile')];
}

/** @return array<string, mixed> */
function proxymaUiProfileData(string $key = 'ui-profile', array $members = []): array
{
    return ['profile_key' => $key, 'member_route_ids' => $members, 'strategy_key' => 'uniform_random', 'reason' => 'Проверка UI authority'];
}

test('до переключения package каталог закрыт и duplicate UI удалён', function (): void {
    $f = proxymaUiFixture();
    DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->update(['authority' => 'legacy', 'generation' => 1, 'cutover_id' => null]);
    $before = DB::table(PackageSchema::tables()['route_profiles'])->get()->all();
    expect(class_exists('App\\Filament\\Pages\\NetworkRouteProfilesPage'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\Procurement\\Crawler\\Proxies\\ProxyResource'))->toBeFalse()
        ->and(RouteProfiles::canAccess())->toBeFalse();
    expect(fn () => app(SaveCatalog::class)->save('profile', $f['profile'], 1, proxymaUiProfileData(), 'legacy:1'))
        ->toThrow(AuthorizationDenied::class);
    expect(fn () => app(CatalogCommands::class)->upsertProfile(new UpsertRouteProfileCommand(new Uuid($f['profile']), TenantKey::system(),
        'forged', [], 'uniform_random', new CatalogMutationActor(new ActorRef('operator', (string) $f['user']->id), 'Прямой вызов', 1, Uuid::random()->value, 'legacy:1'))))
        ->toThrow(AuthorizationDenied::class);
    expect(DB::table(PackageSchema::tables()['route_profiles'])->get()->all())->toEqual($before);
});

test('после переключения профиль меняется только package командой с audit и empty deny', function (): void {
    $f = proxymaUiFixture();
    $legacy = DB::table('prc_network_route_profiles')->get()->all();
    expect(RouteProfiles::canAccess())->toBeTrue();
    $saved = app(SaveCatalog::class)->save('profile', $f['profile'], 1, proxymaUiProfileData('active', []), 'proxyma:2');
    expect($saved->revision)->toBe(2)->and($saved->isEmpty())->toBeTrue()
        ->and(app(CatalogQueries::class)->routesForProfile(TenantKey::system(), $saved->id))->toBe([])
        ->and(DB::table('prc_network_route_profiles')->get()->all())->toEqual($legacy)
        ->and(DB::table(PackageSchema::tables()['audit_events'])->where('entity_id', $saved->id->value)
            ->where('event_kind', 'mutation')->where('revision', 2)->count())->toBe(1);
});

test('replay точный membership не даёт implicit direct и скрывает credentials', function (): void {
    $f = proxymaUiFixture();
    $page = Livewire::test(RouteProfiles::class)->call('edit', $f['profile'])
        ->set('data.member_route_ids', [])->set('data.reason', 'Пустой профиль')->call('save')->assertHasNoErrors()->assertSet('revision', 2)
        ->assertSee('Пустой профиль запрещает отправку');
    $page->set('data.member_route_ids', [$f['route']])->set('data.reason', 'Явный direct')->call('save')->assertHasNoErrors();
    $profile = app(CatalogQueries::class)->findProfile(TenantKey::system(), new Uuid($f['profile']));
    $route = app(CatalogQueries::class)->findRoute(TenantKey::system(), new Uuid($f['route']));
    expect($profile->memberRouteIds)->toHaveCount(1)->and($profile->allowsRoute($route))->toBeFalse()
        ->and($profile->allowsRoute($route, true))->toBeTrue();
    $page->assertDontSee('fixture-caller-hmac-key');
});

test('replay устаревшая membership форма и неизвестный маршрут не дают частичной записи', function (): void {
    $f = proxymaUiFixture();
    $first = Livewire::test(RouteProfiles::class)->call('edit', $f['profile']);
    $stale = Livewire::test(RouteProfiles::class)->call('edit', $f['profile']);
    $first->set('data.member_route_ids', [])->set('data.reason', 'Закрытие')->call('save')->assertHasNoErrors();
    $stale->set('data.reason', 'Старая форма')->call('save')->assertHasErrors(['data']);
    $audit = DB::table(PackageSchema::tables()['audit_events'])->where('event_kind', 'mutation')->count();
    $reads = DB::table(PackageSchema::tables()['audit_events'])->where('event_kind', 'read')->where('entity_id', $f['profile'])->count();
    $receipts = DB::table(PackageSchema::tables()['catalog_command_receipts'])->count();
    expect(fn () => app(SaveCatalog::class)->save('profile', $f['profile'], 2, proxymaUiProfileData('active', [Uuid::random()->value]), 'proxyma:2'))
        ->toThrow(CatalogNotFound::class);
    expect(DB::table(PackageSchema::tables()['audit_events'])->where('event_kind', 'mutation')->count())->toBe($audit)
        ->and(DB::table(PackageSchema::tables()['audit_events'])->where('event_kind', 'read')->where('entity_id', $f['profile'])->count())->toBe($reads + 1)
        ->and(DB::table(PackageSchema::tables()['catalog_command_receipts'])->count())->toBe($receipts)
        ->and((int) DB::table(PackageSchema::tables()['route_profiles'])->where('id', $f['profile'])->value('revision'))->toBe(2)
        ->and(DB::table(PackageSchema::tables()['route_profile_members'])->where('profile_id', $f['profile'])->count())->toBe(0);
});

test('replay без ProxyPermission закрыты navigation URL и прямой action', function (): void {
    $f = proxymaUiFixture();
    test()->actingAs(User::factory()->create());
    expect(RouteProfiles::canAccess())->toBeFalse();
    test()->get(RouteProfiles::getUrl())->assertForbidden();
    $before = DB::table(PackageSchema::tables()['audit_events'])->count();
    expect(fn () => app(SaveCatalog::class)->save('profile', $f['profile'], 1, proxymaUiProfileData(), 'proxyma:2'))
        ->toThrow(AuthorizationDenied::class);
    expect(DB::table(PackageSchema::tables()['audit_events'])->count())->toBe($before);
});

test('forged actor tenant и чужая revision не изменяют профиль', function (): void {
    $f = proxymaUiFixture();
    $commands = app(CatalogCommands::class);
    foreach ([new ActorRef('operator', 'foreign'), new ActorRef('worker', (string) $f['user']->id)] as $actor) {
        expect(fn () => $commands->upsertProfile(new UpsertRouteProfileCommand(new Uuid($f['profile']), TenantKey::system(), 'forged', [], 'uniform_random',
            new CatalogMutationActor($actor, 'Подмена', 1, Uuid::random()->value, 'proxyma:2'))))->toThrow(AuthorizationDenied::class);
    }
    expect(fn () => $commands->upsertProfile(new UpsertRouteProfileCommand(new Uuid($f['profile']), new TenantKey('foreign'), 'forged', [], 'uniform_random',
        new CatalogMutationActor(new ActorRef('operator', (string) $f['user']->id), 'Подмена', 1, Uuid::random()->value, 'proxyma:2'))))->toThrow(AuthorizationDenied::class);
    expect(fn () => app(SaveCatalog::class)->save('profile', $f['profile'], 9, proxymaUiProfileData(), 'proxyma:2'))->toThrow(CatalogConflict::class);
    expect((int) DB::table(PackageSchema::tables()['route_profiles'])->where('id', $f['profile'])->value('revision'))->toBe(1);
});

test('generation fence отвергает старую и отсутствующую generation даже при верной revision', function (): void {
    $f = proxymaUiFixture();
    foreach ([null, 'legacy:1', 'proxyma:1'] as $version) {
        expect(fn () => app(SaveCatalog::class)->save('profile', $f['profile'], 1, proxymaUiProfileData(), $version))->toThrow(CatalogConflict::class);
    }
    $page = Livewire::test(RouteProfiles::class)->call('edit', $f['profile']);
    expect(fn () => $page->set('authorityVersion', 'proxyma:999'))->toThrow(CannotUpdateLockedPropertyException::class);
    DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->increment('generation');
    $page->set('data.reason', 'Форма до переключения')->call('save')->assertHasErrors(['data']);
    expect((int) DB::table(PackageSchema::tables()['route_profiles'])->where('id', $f['profile'])->value('revision'))->toBe(1);
});

test('embedded settings сохраняют только patch и отвергают stale generation без изменения scheduling', function (): void {
    proxymaUiFixture();
    $target = DB::table('prc_source_targets')->whereNotNull('platform_id')->sole();
    $platform = Platform::findOrFail($target->platform_id);
    $sections = app(SettingsSections::class)->forRecord($platform);
    expect($sections)->toHaveCount(1)->and($sections[0])->toBeInstanceOf(Actions::class);
    $before = $platform->getAttributes();
    $subject = new SubjectRef(TenantKey::system(), 'platform', (string) $platform->id);
    $editor = app(BindingEditor::class);
    $data = $editor->open($subject, new Uuid($target->id), 'agelto.acquisition', 'detail');
    $stale = $data;
    $data['reason'] = 'Локальный timeout';
    $data['fields'][$editor::fieldId('timeout.connect_ms')] = ['mode' => 'set', 'value' => 500];
    $editor->save($data);
    $binding = app(BindingQueries::class)->findBinding($subject, 'agelto.acquisition', 'detail');
    expect($binding->patch->changes['timeout.connect_ms']->value)->toBe(500)
        ->and($platform->refresh()->getAttributes())->toEqual($before);
    $stale['reason'] = 'Старый patch';
    expect(fn () => $editor->save($stale))->toThrow(ValidationException::class);
    $fresh = $editor->open($subject, new Uuid($target->id), 'agelto.acquisition', 'detail');
    DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->increment('generation');
    $fresh['reason'] = 'Форма до смены generation';
    expect(fn () => $editor->save($fresh))->toThrow(ValidationException::class);
    expect(app(BindingQueries::class)->findBinding($subject, 'agelto.acquisition', 'detail')->revision)->toBe($binding->revision);
});

test('replay credentials остаются write-only и правка узла не изменяет здоровье', function (): void {
    proxymaUiFixture();
    $id = Uuid::random()->value;
    $saved = app(SaveCatalog::class)->save('endpoint', $id, 0,
        ['host' => 'proxy.fixture.test', 'port' => 8080, 'protocol' => 'http', 'reason' => 'Новый узел'], 'proxyma:2');
    $rotated = app(SaveCatalog::class)->rotate($id, $saved->revision, 'Новый секрет',
        ['username' => 'private-fixture-user', 'password' => 'private-fixture-password'], 'proxyma:2');
    $health = DB::table(PackageSchema::tables()['route_health'])->get()->all();
    Livewire::test(Endpoints::class)->call('edit', $id)
        ->assertDontSee('private-fixture-user')->assertDontSee('private-fixture-password')->assertSet('credentialData', [])
        ->set('data.host', 'renamed.fixture.test')->set('data.reason', 'Переименование')->set('data.open_count', 999)
        ->call('save')->assertHasNoErrors();
    $current = app(CatalogQueries::class)->findEndpoint(TenantKey::system(), new Uuid($id));
    expect($current->credential)->toEqual($rotated->credential)
        ->and($current->host)->toBe('renamed.fixture.test')
        ->and(DB::table(PackageSchema::tables()['route_health'])->get()->all())->toEqual($health);
});

test('устаревшая добавка на прокси читается как metadata и не увеличивает origin quota', function (): void {
    proxymaUiFixture();
    $settings = PlatformThrottleSettings::validateAndCreate(['requestsPerMinuteBase' => 20, 'requestsPerMinutePerProxy' => 500]);
    expect($settings->requestsPerMinutePerProxy)->toBe(500);
    $origin = CanonicalOrigin::fromUrl(DB::table('prc_source_targets')->sole()->canonical_origin);
    $before = DB::table(PackageSchema::tables()['budgets'])->where('scope_key', $origin->budgetScopeKey())->sole();
    app(SaveCatalog::class)->save('route', Uuid::random()->value, 0, ['route_key' => 'extra-direct', 'kind' => 'direct', 'endpoint_id' => null,
        'enabled' => true, 'egress_key' => 'nat:extra', 'conservative_pool' => false, 'independence_group' => null, 'reason' => 'Ещё маршрут'], 'proxyma:2');
    expect(DB::table(PackageSchema::tables()['budgets'])->where('scope_key', $origin->budgetScopeKey())->sole())->toEqual($before);
    $source = file_get_contents(app_path('Filament/Resources/Procurement/Platform/Platforms/Schemas/PlatformForm.php'));
    expect($source)->not->toContain("TextInput::make('throttle.requestsPerMinutePerProxy')");
});

test('owner форма сохраняет scheduling и отвергает generation старого редактора', function (): void {
    proxymaUiFixture();
    $crawler = AcquisitionProfileFixtures::create();
    $page = Livewire::test(EditCrawler::class,
        ['record' => $crawler->id]);
    $filter = $crawler->filter->toArray();
    $page->fillForm(['schedule' => '15 * * * *'])->call('save')->assertHasNoFormErrors();
    expect($crawler->refresh()->schedule)->toBe('15 * * * *')->and($crawler->filter->toArray())->toEqual($filter);
    $from = AcquisitionProfileFixtures::sourceDay()->addDay();
    $to = $from->addDay();
    $page->fillForm(['filter.publishedFrom' => $from->toDateString(), 'filter.publishedTo' => $to->toDateString()])
        ->call('save')->assertHasNoFormErrors();
    $editedFilter = [...$filter, 'publishedFrom' => $from->toAtomString(), 'publishedTo' => $to->toAtomString()];
    expect($crawler->refresh()->schedule)->toBe('15 * * * *')->and($crawler->filter->toArray())->toEqual($editedFilter);
    DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->increment('generation');
    $page->fillForm(['schedule' => '30 * * * *'])->call('save')->assertHasErrors(['data']);
    expect($crawler->refresh()->schedule)->toBe('15 * * * *')->and($crawler->filter->toArray())->toEqual($editedFilter);
});

function proxymaRestoreProductionBindings(): void
{
    app()->register(ProxymaServiceProvider::class, force: true);
    app()->forgetScopedInstances();
    DB::connection()->setTransactionManager(app('db.transactions'));
    expect(app(Authorizer::class))->toBeInstanceOf(AgeltoAuthorizer::class)
        ->and(app(TenantContextResolver::class))->toBeInstanceOf(AgeltoTenantContext::class);
}

function proxymaRuntimeContext(PermitFixtures $fixture): OutboundExecutionContext
{
    $context = $fixture->context();
    DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update([
        'platform_id' => DB::table('prc_source_targets')->where('id', $fixture->target->value)->value('platform_id'),
    ]);

    return $context;
}

function proxymaObserveRuntimeTimeout(): object
{
    $observed = new class
    {
        public array $timeouts = [];

        public array $limits = [];
    };
    app()->extend(PhysicalTransport::class, fn ($transport) => new class($transport, $observed) implements PhysicalTransport
    {
        public function __construct(private PhysicalTransport $transport, private object $observed) {}

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            $this->observed->timeouts[] = [$request->wire->timeouts->connectTimeoutMs, $request->wire->trafficClass];
            $this->observed->limits[] = [$request->wire->timeouts->maxAttempts, $request->wire->timeouts->maxRedirects,
                $request->wire->timeouts->retryDelaysMs, $request->wire->timeouts->maxDistinctRoutes];

            return $this->transport->sendOnce($request);
        }
    });

    return $observed;
}

test('production worker без Auth отправляет только с live owner и не получает UI mutation grant', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        proxymaRestoreProductionBindings();
        Auth::logout();
        $context = proxymaRuntimeContext($f);
        $runtime = app(RuntimeCaller::class);
        expect(auth()->user())->toBeNull();
        expect(fn () => app(CatalogAccess::class)->authorize(TenantKey::system(), 'policy.resolve', Uuid::random()))
            ->toThrow(AuthorizationDenied::class);
        expect(fn () => $runtime->run($f->plan(), $context, function (): void {
            $actor = app(TenantContextResolver::class)->resolve()->actor;
            app(CatalogCommands::class)->upsertProfile(new UpsertRouteProfileCommand(Uuid::random(), TenantKey::system(), 'forbidden-runtime-edit', [], 'uniform_random',
                new CatalogMutationActor($actor, 'Попытка UI mutation', 0, Uuid::random()->value, 'proxyma:2')));
        }))->toThrow(AuthorizationDenied::class);
        expect($runtime->context())->toBeNull()->and($wire->receipts())->toBe([]);
        $execute = fn () => app(OutboundExecutor::class)->execute($f->plan(), $context,
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($execute())->toBeInstanceOf(TransportSucceeded::class)
            ->and($wire->receipts())->toHaveCount(1)->and($runtime->context())->toBeNull();
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
            ->update(['state' => 'closed', 'finished_at' => DB::raw('clock_timestamp()')]);
        expect($execute())->toBeInstanceOf(TransportBlocked::class)
            ->and($wire->receipts())->toHaveCount(1)->and($runtime->context())->toBeNull();
    } finally {
        $wire->stop();
    }
});

test('embedded platform detail patch применяется к production HTTP без Auth', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        proxymaRestoreProductionBindings();
        $user = User::factory()->create();
        $user->assignRole(OperatorRole::class);
        test()->actingAs($user);
        $platform = DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id');
        $editor = app(BindingEditor::class);
        $data = $editor->open(new SubjectRef(TenantKey::system(), 'platform', (string) $platform), new Uuid($f->target->value), 'agelto.acquisition', 'detail');
        $data['fields'][$editor::fieldId('timeout.connect_ms')] = ['mode' => 'set', 'value' => 500];
        $data['reason'] = 'Настройка реального detail caller';
        $editor->save($data);
        Auth::logout();
        $observed = proxymaObserveRuntimeTimeout();
        $result = app(OutboundExecutor::class)->execute($f->plan(), proxymaRuntimeContext($f),
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($observed->timeouts)->toBe([[500, 'detail']])->and($wire->receipts())->toHaveCount(1)->and(auth()->user())->toBeNull();
    } finally {
        $wire->stop();
    }
});

test('embedded crawler listing patch использует persisted owner chain в production caller', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $crawler = EisCrawlerFactory::new()->create(['registry_id' => $fixture->registry->id,
            'route_profile_id' => $fixture->routeProfile->value, 'filter' => new EisSearchFilter(okpd2Codes: ['43'])]);
        ProxymaCallerFixtures::install($wire);
        proxymaRestoreProductionBindings();
        $user = User::factory()->create();
        $user->assignRole(OperatorRole::class);
        test()->actingAs($user);
        $target = DB::table('prc_source_targets')->where('platform_id', $fixture->registry->platform_id)->where('capability', 'listing')->sole()->id;
        $editor = app(BindingEditor::class);
        $data = $editor->open(new SubjectRef(TenantKey::system(), 'crawler', (string) $crawler->id), new Uuid($target), 'agelto.acquisition', 'listing');
        $data['fields'][$editor::fieldId('timeout.connect_ms')] = ['mode' => 'set', 'value' => 500];
        $data['reason'] = 'Настройка реального listing owner';
        $editor->save($data);
        Auth::logout();
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $observed = proxymaObserveRuntimeTimeout();
        $fixture->fetchWork($fixture->listingRequest(), function ($context) use ($crawler): void {
            $run = DB::table('prc_process_work')->where('id', $context->lease()->workId->value->value)->value('origin_run_id');
            expect($run)->not->toBeNull();
            DB::table('prc_process_runs')->where('id', $run)->update(['profile_id' => $crawler->id]);
        });
        expect($observed->timeouts)->toBe([[500, 'listing']])->and($wire->receipts())->toHaveCount(1)->and(auth()->user())->toBeNull();
    } finally {
        $wire->stop();
    }
});

test('package UI profile revision не блокирует новый production request и не переписывает snapshot mapping', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        proxymaRestoreProductionBindings();
        $user = User::factory()->create();
        $user->assignRole(OperatorRole::class);
        test()->actingAs($user);
        $link = DB::table((new ProfileLink)->getTable())->where('source_profile_id', app(ProxyResolver::class)->profile()->profileId->value)->sole();
        Livewire::test(RouteProfiles::class)->call('edit', $link->profile_id)->set('data.reason', 'Обычное сохранение профиля')
            ->call('save')->assertHasNoErrors()->assertSet('revision', 2);
        $bindings = app(BindingAdapter::class);
        expect($bindings->activeProfile(new ProcessUuid($link->source_profile_id))->revision)->toBe(2);
        $snapshot = new AcquisitionConfiguration(1, new ProcessUuid($link->source_profile_id), $link->source_version,
            AcquisitionKind::Fresh, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02'), null, null);
        expect($bindings->snapshotProfile($snapshot, StageCode::Listing))->toBe(['profile_id' => $link->profile_id, 'profile_version' => 1]);
        Auth::logout();
        $result = app(OutboundExecutor::class)->execute($f->plan(), proxymaRuntimeContext($f),
            app()->makeWith(ScheduledAttemptGate::class, ['routes' => app(ProxyResolver::class)->outboundRoutes(
                app(ProxyResolver::class)->profile(new ProcessUuid($link->source_profile_id)))]),
            $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($wire->receipts())->toHaveCount(1);
        test()->actingAs($user);
        Livewire::test(RouteProfiles::class)->call('edit', $link->profile_id)->set('data.member_route_ids', [])
            ->set('data.reason', 'Пустой активный профиль')->call('save')->assertHasNoErrors()->assertSet('revision', 3);
        Auth::logout();
        $denied = app(OutboundExecutor::class)->execute($f->plan(), proxymaRuntimeContext($f),
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($denied)->toBeInstanceOf(TransportDeferred::class)
            ->and($wire->receipts())->toHaveCount(1)
            ->and($bindings->snapshotProfile($snapshot, StageCode::Listing)['profile_version'])->toBe(1);
    } finally {
        $wire->stop();
    }
});

test('production provider без Auth сохраняет joint claim receipt и финансовый settlement', function (bool $loseOwner): void {
    $wire = new WireFixture;
    try {
        $f = new ProviderSendFixtures($wire);
        new ProxymaProviderFixtures($f);
        proxymaRestoreProductionBindings();
        Auth::logout();
        if ($loseOwner) {
            app()->extend(PhysicalTransport::class, fn ($transport) => new class($transport, $f->work->value->value) implements PhysicalTransport
            {
                public function __construct(private PhysicalTransport $transport, private string $work) {}

                public function sendOnce(AuthorizedWireRequest $request): AttemptResult
                {
                    $result = $this->transport->sendOnce($request);
                    DB::table('prc_process_work')->where('id', $this->work)->increment('generation');

                    return $result;
                }
            });
        }
        $result = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
        $call = app(RequestRepository::class)->get($f->operation->physicalCallId);
        expect($result)->toBeInstanceOf(ProviderTransportOutcome::class);
        expect($result->status)->toBe(200)->and($wire->receipts())->toHaveCount(1)
            ->and($call->receiptHash)->not->toBeNull()->and($call->requestState)->toBe('completed')
            ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('settled')
            ->and(app(RuntimeCaller::class)->context())->toBeNull()
            ->and(auth()->user())->toBeNull();
        $tables = PackageSchema::tables();
        expect(DB::table($tables['permits'])->sole()->state)->toBe('completed');
    } finally {
        $wire->stop();
    }
})->with(['live owner' => false, 'owner lost after send' => true]);

/** @param array<string, mixed> $patch
 * @param  list<int>  $delays
 * @return array{PermitFixtures, OutboundRequestPlan, object}
 */
function proxymaLoopSettings(WireFixture $wire, array $patch, int $attempts, int $redirects, array $delays): array
{
    $fixture = new PermitFixtures($wire);
    $profile = RequestProfile::fromArray(array_replace($fixture->profile->toArray(), [
        'attempts' => $attempts, 'redirects' => $redirects, 'distinct_routes' => 1, 'delays_ms' => $delays,
    ]));
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($fixture->code, [
        new SourceTargetDefinition('card', 'detail', $fixture->url->origin, [$profile]),
    ]));
    HistoricalNetworkFixtures::synchronize();
    ProxymaCallerFixtures::install($wire);
    proxymaRestoreProductionBindings();
    $user = User::factory()->create();
    $user->assignRole(OperatorRole::class);
    test()->actingAs($user);
    $platform = DB::table('prc_source_targets')->where('id', $fixture->target->value)->value('platform_id');
    $editor = app(BindingEditor::class);
    $data = $editor->open(new SubjectRef(TenantKey::system(), 'platform', (string) $platform), new Uuid($fixture->target->value), 'agelto.acquisition', 'detail');
    foreach ($patch as $key => $value) {
        $data['fields'][$editor::fieldId($key)] = ['mode' => 'set', 'value' => $value];
    }
    $data['reason'] = 'Ограничения owning HTTP loop';
    $editor->save($data);
    Auth::logout();
    $plan = new OutboundRequestPlan(ProcessUuid::random(), $fixture->target, 'GET', $fixture->url,
        $profile, TrafficClass::Detail, IdempotencyClass::ReadOnly);

    return [$fixture, $plan, proxymaObserveRuntimeTimeout()];
}

test('embedded loop limits запрещают второй physical send на 302 и 503', function (int $status, bool $callerStricter): void {
    $wire = new WireFixture;
    try {
        [$f, $plan, $observed] = proxymaLoopSettings($wire, [
            'retry.max_attempts' => $callerStricter ? 3 : 1, 'http.max_redirects' => $callerStricter ? 1 : 0,
        ], $callerStricter ? 1 : 3, $callerStricter ? 0 : 1, $callerStricter ? [] : [60_000, 60_000]);
        $wire->respond('/ok', 'first', status: $status, headers: ['Location' => '/second']);
        $wire->respond('/second', 'must not be sent');
        $wire->respond('/ok', 'must not be retried');
        $result = app(OutboundExecutor::class)->execute($plan, proxymaRuntimeContext($f),
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($result)->toBeInstanceOf($status === 302 ? TransportBlocked::class : TransportDeferred::class)
            ->and($wire->receipts())->toHaveCount(1)->and($observed->limits)->toBe([[1, 0, [], 1]])
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(1)
            ->and(auth()->user())->toBeNull();
    } finally {
        $wire->stop();
    }
})->with(['policy redirect' => [302, false], 'policy retry' => [503, false],
    'caller redirect' => [302, true], 'caller retry' => [503, true]]);

test('embedded retry delay ограничивает caller jitter перед следующей отправкой', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $plan, $observed] = proxymaLoopSettings($wire, ['retry.max_attempts' => 2,
            'http.max_redirects' => 1, 'retry.delays_ms' => [0]], 2, 1, [60_000]);
        $wire->respond('/ok', 'retry', status: 503);
        $wire->respond('/ok', 'done');
        $result = app(OutboundExecutor::class)->execute($plan, proxymaRuntimeContext($f),
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($wire->receipts())->toHaveCount(2)
            ->and($observed->limits)->toBe([[2, 1, [0], 1], [2, 1, [0], 1]]);
    } finally {
        $wire->stop();
    }
});

test('embedded loop limits разрешают один redirect при attempts равном одному', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $plan, $observed] = proxymaLoopSettings($wire, ['retry.max_attempts' => 1,
            'http.max_redirects' => 1], 1, 1, []);
        $wire->respond('/ok', 'redirect', status: 302, headers: ['Location' => '/second']);
        $wire->respond('/second', 'done');
        $result = app(OutboundExecutor::class)->execute($plan, proxymaRuntimeContext($f),
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(65_536, 65_536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($wire->receipts())->toHaveCount(2)
            ->and($observed->limits)->toBe([[1, 1, [], 1], [1, 1, [], 1]]);
    } finally {
        $wire->stop();
    }
});
