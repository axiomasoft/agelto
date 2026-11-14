<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\User;
use App\Providers\ProxymaServiceProvider;
use App\Repositories\Procurement\Document\DownloadStorageRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Document\DownloadHandler;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Platform\ExecutorPlatformOutbound;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Proxyma\CallerGate;
use App\Services\Procurement\Proxyma\CallerOutbound;
use App\Services\Procurement\Proxyma\CallerSendFacts;
use App\Services\Procurement\Proxyma\ProfileResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;

beforeEach(function (): void {
    ProxymaFixtures::clean();
    DocumentStorageFixtures::reset();
});
afterEach(fn () => ProxymaFixtures::clean());

function downloadAccountingOwnerPlatform(DownloadFixtures $fixture, WorkId $work): int
{
    $platform = DB::table('prc_source_targets')->where('id', $fixture->network->target->value)->sole()->platform_id;
    expect($platform)->toBeInt();
    $registry = Registry::query()->where('platform_id', $platform)->where('code', 'fz44')->firstOrFail();
    Procurement::query()->whereKey($fixture->document->procurement_id)->update([
        'platform_id' => $platform, 'registry_id' => $registry->id,
    ]);
    $fixture->document->update(['platform_id' => $platform]);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['platform_id' => $platform]);

    return $platform;
}

test('download и один signed refresh входят в тот же package physical ceiling', function (bool $gzip): void {
    $wire = new WireFixture;
    try {
        $wire->respond('/document', 'expired', status: 403);
        $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
        $wire->respond('/refreshed', $gzip ? gzencode($content) : $content,
            headers: ['Content-Type' => 'application/pdf', ...($gzip ? ['Content-Encoding' => 'gzip'] : [])]);
        $f = new DownloadFixtures($wire);
        [$input, $work, $context] = $f->running();
        downloadAccountingOwnerPlatform($f, $work);
        ProxymaCallerFixtures::install($wire);
        $outcome = $f->handler(ProxymaCallerFixtures::gate($f->network->route->routeId->value))->execute($input, $context);
        expect(app(DownloadStorageRepository::class)->sends($context->lease()))->toBe(2)
            ->and($f->access->refreshes)->toBe(1)->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0);
        app(WorkFinalizer::class)->finish($context->lease(), $outcome);
        $version = DB::table('prc_document_versions')->selectRaw("encode(content_hash,'hex') AS hash")->sole();
        expect($version->hash)->toBe(hash('sha256', $content));
        $operation = DB::table(PackageSchema::tables()['operations'])->first();
        expect($operation->parent_attempt_id)->toBe($context->lease()->attemptId->value);
    } finally {
        $wire->stop();
    }
})->with(['identity' => false, 'gzip' => true]);

test('исчерпанный download ceiling запрещает HTTP после signed refresh', function (): void {
    $wire = new WireFixture;
    try {
        $wire->respond('/document', 'expired', status: 403);
        $wire->respond('/refreshed', 'must not be sent');
        $f = new DownloadFixtures($wire);
        $f->sendLimit = 1;
        [$input, $work, $context] = $f->running();
        downloadAccountingOwnerPlatform($f, $work);
        ProxymaCallerFixtures::install($wire);
        $result = $f->handler(ProxymaCallerFixtures::gate($f->network->route->routeId->value))->execute($input, $context);
        expect($result)->toBeInstanceOf(StageDeferred::class)
            ->and($f->access->refreshes)->toBe(1)->and($wire->receipts())->toHaveCount(1)
            ->and(app(DownloadStorageRepository::class)->sends($context->lease()))->toBe(1)
            ->and(DB::table('prc_document_versions')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
});

test('дочерний canary учитывается по стабильному parent attempt при отдельном operation owner', function (): void {
    $wire = new WireFixture;
    try {
        $wire->respond('/document', file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf')));
        $f = new DownloadFixtures($wire);
        [, $work, $parent] = $f->running();
        $platform = downloadAccountingOwnerPlatform($f, $work);
        ProxymaCallerFixtures::install($wire);
        $snapshot = app(NetworkOwnerFence::class)->snapshot($parent->lease());
        $controls = array_values(array_unique([...array_map(fn ($c) => $c->scopeKey, $snapshot->controls), 'monitoring']));
        $tx = app(ProcessTransaction::class);
        $operations = app(OutboundOperationRepository::class);
        $token = LedgerFixtures::uuid();
        $owner = $tx->run(fn () => $operations->open(LedgerFixtures::uuid(), $token,
            OutboundOperationKind::Probe, $platform, 'document.download_canary',
            $parent->lease()->inputHash, $controls, 120, 120, probeToken: $token, parentAttemptId: $parent->lease()->attemptId));
        $context = new OutboundExecutionContext($owner,
            fn ($handle) => $tx->run(fn () => $operations->renew($handle, 120)), $parent->tick(...), 120, 15);
        try {
            $result = app(CallerOutbound::class)->execute($f->plan('/document')->request, $context,
                ProxymaCallerFixtures::gate($f->network->route->routeId->value), $wire->policy(), new TransferBudget(262144, 262144));
            expect($result)->toBeInstanceOf(TransportSucceeded::class)
                ->and(app(DownloadStorageRepository::class)->sends($parent->lease()))->toBe(1)
                ->and($wire->receipts())->toHaveCount(1)
                ->and(DB::table(PackageSchema::tables()['operations'])->sole()->owner_id)->toBe($owner->id->value)
                ->and(DB::table(PackageSchema::tables()['operations'])->sole()->parent_attempt_id)->toBe($parent->lease()->attemptId->value);
            Storage::disk($result->response->body->artifact->disk)->delete($result->response->body->artifact->key);
        } finally {
            $tx->run(fn () => $operations->close($owner));
        }
    } finally {
        $wire->stop();
    }
});

test('download с чужой platform в ancestry не отправляет bytes', function (): void {
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [, $work, $context] = $f->running();
        $platform = downloadAccountingOwnerPlatform($f, $work);
        $foreign = Registry::factory()->create();
        expect($foreign->platform_id)->not->toBe($platform);
        Procurement::query()->whereKey($f->document->procurement_id)->update([
            'platform_id' => $foreign->platform_id, 'registry_id' => $foreign->id,
        ]);
        ProxymaCallerFixtures::install($wire);
        $result = app(CallerOutbound::class)->execute($f->plan('/document')->request, $context,
            ProxymaCallerFixtures::gate($f->network->route->routeId->value), $wire->policy(), new TransferBudget(262144, 262144));
        expect($result)->toBeInstanceOf(TransportBlocked::class)->and($result->reason)->toBe(NetworkBlockedReason::OwnerLost)
            ->and($wire->receipts())->toBe([])->and(app(DownloadStorageRepository::class)->sends($context->lease()))->toBe(0);
    } finally {
        $wire->stop();
    }
});

test('download после UI добавления package route игнорирует пустой legacy membership', function (bool $probe): void {
    $wire = new WireFixture;
    try {
        $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
        $wire->respond('/document', $content, headers: ['Content-Type' => 'application/pdf']);
        $f = new DownloadFixtures($wire);
        [$input, $work, $context] = $f->running();
        $platform = downloadAccountingOwnerPlatform($f, $work);
        ProxymaFixtures::resetAuthority();
        $resolver = app(ProfileResolver::class);
        $legacyProfile = $resolver->profile()->profileId;
        DB::table('prc_network_route_profile_members')->where('profile_id', $legacyProfile->value)->delete();
        $routes = $resolver->profile($legacyProfile);
        expect($routes->bindings)->toBe([])->and(CallerSendFacts::active())->toBeFalse();
        $legacy = DB::table('prc_network_route_profiles')->orderBy('id')->get()->all();
        ProxymaCallerFixtures::install($wire);
        app()->register(ProxymaServiceProvider::class, force: true);
        app()->forgetScopedInstances();
        DB::connection()->setTransactionManager(app('db.transactions'));
        Artisan::call('guard:sync-roles');
        $handler = app()->makeWith(DownloadHandler::class, ['access' => $f->access,
            'outbound' => new ExecutorPlatformOutbound(app(CallerOutbound::class),
                app()->makeWith(CallerGate::class, ['routes' => $resolver->outboundRoutes($routes)]),
                $wire->policy(), new TransferBudget(262144, 262144), $routes, $platform)]);
        Auth::logout();
        expect(CallerSendFacts::active())->toBeTrue()
            ->and($handler->execute($input, $context))->toBeInstanceOf(StageDeferred::class)
            ->and($wire->receipts())->toBe([]);
        $user = User::factory()->create();
        $user->assignRole(OperatorRole::class);
        test()->actingAs($user);
        $link = DB::table((new ProfileLink)->getTable())->where('source_profile_id', $legacyProfile->value)->sole();
        Livewire::test(RouteProfiles::class)->call('edit', $link->profile_id)
            ->set('data.member_route_ids', [$f->network->route->routeId->value])->set('data.reason', 'Разрешённый package маршрут')
            ->call('save')->assertHasNoErrors()->assertSet('revision', 2);
        Auth::logout();
        if ($probe) {
            DB::table(PackageSchema::tables()['target_health'])->where('target_id', $f->network->target->value)
                ->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() - interval '1 second'"), 'availability' => 'unavailable']);
        }
        $outcome = $handler->execute($input, $context);
        app(WorkFinalizer::class)->finish($context->lease(), $outcome);
        expect($wire->receipts())->toHaveCount(1)->and(app(DownloadStorageRepository::class)->sends($context->lease()))->toBe(1)
            ->and(DB::table('prc_document_versions')->selectRaw("encode(content_hash,'hex') AS hash")->sole()->hash)->toBe(hash('sha256', $content))
            ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $legacyProfile->value)->count())->toBe(0)
            ->and(DB::table('prc_network_route_profiles')->orderBy('id')->get()->all())->toEqual($legacy)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)->and(auth()->user())->toBeNull();
    } finally {
        $wire->stop();
    }
})->with(['healthy package route' => false, 'due package probe' => true]);
