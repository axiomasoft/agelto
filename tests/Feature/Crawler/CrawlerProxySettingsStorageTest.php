<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Models\User;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

beforeEach(function (): void {
    PermitFixtures::clean();
    LegacyBusinessNetwork::install(fakeResponses: false);
});
afterEach(fn () => PermitFixtures::clean());

test('настройка crawler proxy хранит nullable ID в выделенном репозитории', function (): void {
    $repository = app(RouteRepository::class);
    app(ProcessTransaction::class)->run(fn () => $repository->ensureProfile('storage.fixture'));
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'storage.fixture')->sole();
    $policy = app(ProxyResolver::class)->profile(new ProcessUuid($profile->id));
    // Empty exact profile replaces nullable default node; it never implies a direct grant.
    expect($policy->profileId->value)->toBe($profile->id)->and($policy->bindings)->toBe([])
        ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->count())->toBe(0);
});

test('resolver обновляет cached settings после сохранения другим process', function (): void {
    $repository = app(RouteRepository::class);
    $route = app(ProcessTransaction::class)->run(fn () => $repository->ensureDirectRoute('storage.fixture', null));
    app(ProcessTransaction::class)->run(fn () => $repository->ensureProfile('storage.fixture'));
    $profile = new ProcessUuid(DB::table('prc_network_route_profiles')->where('profile_key', 'storage.fixture')->sole()->id);
    $resolver = app(ProxyResolver::class);
    expect($resolver->profile($profile)->bindings)->toBe([]);
    $actor = User::factory()->create();
    try {
        $code = <<<'WORKER'
return app(\App\Services\Procurement\Process\ProcessTransaction::class)->run(function () {
    $change = app(\App\Repositories\Procurement\Network\RouteRepository::class)->updateProfile(
        'storage.fixture', 1, ['__ROUTE__'], __ACTOR__, 'fixture external update',
        new \App\Dto\Procurement\Process\ProcessUuid('__EVENT__'), new \App\Dto\Procurement\Process\ProcessUuid('__MESSAGE__'));
    return ['status' => $change->status->value, 'version' => $change->policyVersion,
        'pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
});
WORKER;
        $result = ConcurrentTransactions::execute(strtr($code, ['__ROUTE__' => $route, '__ACTOR__' => (string) $actor->id,
            '__EVENT__' => LedgerFixtures::uuid()->value, '__MESSAGE__' => LedgerFixtures::uuid()->value]));
        expect($result['version'])->toBe(2)
            ->and($result['pid'])->not->toBe(DB::scalar('select pg_backend_pid()'))
            ->and(array_keys($resolver->profile($profile)->bindings))->toBe([$route]);
    } finally {
        $actor->delete();
    }
});
