<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\DaemonKind;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;
use App\Services\Health\ApplicationReadiness;
use Database\Seeders\BaseSeeder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('readiness.storage.disk', 'local');
    config()->set('cms.admin_email', 'admin@example.test');

    $this->seed(BaseSeeder::class);

    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $admin->assignRole(SuperAdminRole::class);

    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andReturn([(object) ['status' => 'running']]);
    $this->instance(MasterSupervisorRepository::class, $masters);

    ApplicationReadiness::recordSchedulerHeartbeat();

    foreach (DaemonKind::activeKinds() as $kind) {
        app(DaemonHeartbeatRepository::class)->register(LedgerFixtures::uuid(), $kind);
    }
});

test('возвращает упорядоченный green readiness и убирает активные probes', function (): void {
    $results = app(ApplicationReadiness::class)->check();

    expect($results)->toHaveCount(8)
        ->and(array_column(array_map(fn ($result): array => $result->toArray(), $results), 'code'))
        ->toBe(['database', 'cache', 'storage', 'release_state', 'assets', 'horizon', 'scheduler', 'process_daemons'])
        ->and(collect($results)->every(fn ($result): bool => $result->passed))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('health'))->toBe([])
        ->and(app(CacheFactory::class)->store()->get(ApplicationReadiness::SchedulerHeartbeatKey))->toBeString();
});

test('app:readiness и health каждой активной роли зелёные при настоящих свежих heartbeat', function (): void {
    $results = app(ApplicationReadiness::class)->check();

    expect(app(ApplicationReadiness::class)->isGreen())->toBeTrue();
    foreach (DaemonKind::activeKinds() as $kind) {
        $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
            ->expectsOutput($kind->value.': healthy')
            ->assertExitCode(0);
    }
    $this->artisan('app:readiness', ['--format' => 'json'])
        ->expectsOutput(json_encode(array_map(fn ($result): array => $result->toArray(), $results), JSON_THROW_ON_ERROR))
        ->assertExitCode(0);
});

test('readiness приложения и CLI не скрывают отсутствующий, stale или постоянно ошибающийся daemon', function (DaemonKind $kind, string $failure): void {
    $heartbeat = DB::table('prc_process_daemon_heartbeats')->where('daemon', $kind->value);

    if ($failure === 'missing') {
        $heartbeat->delete();
    } elseif ($failure === 'stale') {
        $heartbeat->update(['last_tick_at' => DB::raw("clock_timestamp() - interval '31 seconds'")]);
    } else {
        // Обновление liveness при сохраняющейся dependency/processing ошибке не лечит readiness.
        foreach ([1, 2] as $tick) {
            $heartbeat->update(['last_tick_at' => DB::raw('clock_timestamp()'), 'last_error_code' => $failure]);
        }
    }

    $results = app(ApplicationReadiness::class)->check();
    $daemons = collect($results)->sole(fn ($result): bool => $result->code->value === 'process_daemons');
    $legacy = collect($results)->reject(fn ($result): bool => $result->code->value === 'process_daemons');
    $payload = json_encode(array_map(fn ($result): array => $result->toArray(), $results), JSON_THROW_ON_ERROR);

    expect($daemons->toArray())->toBe(['code' => 'process_daemons', 'passed' => false, 'message' => 'unavailable'])
        ->and($legacy)->toHaveCount(7)
        ->and($legacy->every(fn ($result): bool => $result->passed))->toBeTrue()
        ->and(app(ApplicationReadiness::class)->isGreen())->toBeFalse()
        ->and($payload)->not->toContain('last_error_code', 'redis_publish_failed', 'tick_failed');

    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': '.(in_array($failure, ['missing', 'stale'], true) ? 'down' : 'degraded'))
        ->assertExitCode(1);
    $this->artisan('app:readiness', ['--format' => 'json'])->expectsOutput($payload)->assertExitCode(1);
})->with([DaemonKind::Relay, DaemonKind::Reconcile, DaemonKind::Feed])
    ->with(['missing', 'stale', 'redis_publish_failed', 'tick_failed']);

test('будущий projector не входит в readiness ни отсутствующим, ни degraded, ни stale', function (): void {
    $readiness = app(ApplicationReadiness::class);
    expect($readiness->isGreen())->toBeTrue();

    $heartbeats = app(DaemonHeartbeatRepository::class);
    $project = LedgerFixtures::uuid();
    $heartbeats->register($project, DaemonKind::Project);
    $heartbeats->beat($project, 'projection_codec_unknown');
    expect($readiness->isGreen())->toBeTrue();

    DB::table('prc_process_daemon_heartbeats')->where('instance_id', $project->value)
        ->update(['last_tick_at' => DB::raw("clock_timestamp() - interval '31 seconds'")]);
    expect($readiness->isGreen())->toBeTrue();
    $this->artisan('process:daemon', ['daemon' => 'project', '--health' => true])->assertExitCode(1);
});

test('возвращает стабильный redacted код при stale scheduler heartbeat', function (): void {
    Cache::put(
        key: ApplicationReadiness::SchedulerHeartbeatKey,
        value: now()->subMinutes(3)->toIso8601String(),
        ttl: now()->addMinutes(10),
    );

    $scheduler = collect(app(ApplicationReadiness::class)->check())
        ->first(fn ($result): bool => $result->code->value === 'scheduler');

    expect($scheduler->passed)->toBeFalse()
        ->and($scheduler->message)->toBe('unavailable');
});

test('запись heartbeat отклоняет нетипизированный ttl', function (): void {
    config()->set('readiness.scheduler.ttl_minutes', '10');

    expect(fn () => ApplicationReadiness::recordSchedulerHeartbeat())
        ->toThrow(LogicException::class, 'должен быть положительным целым числом');
});
