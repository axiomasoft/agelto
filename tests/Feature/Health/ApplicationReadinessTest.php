<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use App\Services\Health\ApplicationReadiness;
use Database\Seeders\BaseSeeder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

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
});

test('возвращает упорядоченный green readiness и убирает активные probes', function (): void {
    $results = app(ApplicationReadiness::class)->check();

    expect($results)->toHaveCount(7)
        ->and(array_column(array_map(fn ($result): array => $result->toArray(), $results), 'code'))
        ->toBe(['database', 'cache', 'storage', 'release_state', 'assets', 'horizon', 'scheduler'])
        ->and(collect($results)->every(fn ($result): bool => $result->passed))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('health'))->toBe([])
        ->and(app(CacheFactory::class)->store()->get(ApplicationReadiness::SchedulerHeartbeatKey))->toBeString();
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
