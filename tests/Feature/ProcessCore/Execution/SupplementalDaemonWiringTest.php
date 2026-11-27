<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Services\Procurement\Process\Daemons\DaemonPolicy;
use Symfony\Component\Yaml\Yaml;

pest()->group('delivery-and-daemon-lifecycle');

test('dev и prod разрешают четыре core daemon services на образе app с собственными командами и healthchecks', function (string $file, string $image, array $volumes): void {
    // Разрешаем YAML aliases/merge и image/project placeholders фиксированными fixture values.
    // Ни .env, ни Docker socket, ни запуск dev/prod services этому контрактному тесту не нужны.
    $source = file_get_contents(base_path($file));
    expect($source)->toBeString();
    $compose = Yaml::parse(strtr($source, [
        '${COMPOSE_PROJECT_NAME:-agelto}' => 'agelto_daemon_contract',
        '${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}' => 'agelto_daemon_contract',
        '${IMAGE_TAG:?IMAGE_TAG is required}' => 'daemon-contract',
        '${HORIZON_STOP_GRACE_PERIOD:-370s}' => '370s',
    ]));
    $services = $compose['services'];
    $app = $services['app'];
    $roles = ['process-relay' => 'relay', 'process-reconciler' => 'reconcile', 'process-feeder' => 'feed', 'process-projector' => 'project'];

    expect(array_values(array_filter(array_keys($services), fn (string $name): bool => str_starts_with($name, 'process-'))))
        ->toBe(array_keys($roles))
        ->and($app['image'])->toBe($image)
        ->and($app['secrets'])->toBe(['app_key', 'db_password', 'redis_password', 'minio_app_password'])
        ->and($app['environment']['AWS_SECRET_ACCESS_KEY_FILE'])->toBe('/run/secrets/minio_app_password')
        ->and($services['doctor']['secrets'])->toBe($app['secrets'])
        ->and($services['horizon']['secrets'])->toBe($app['secrets'])
        ->and($services['scheduler']['secrets'])->toBe($app['secrets'])
        ->and($services['minio']['secrets'])->toBe(['minio_root_password'])
        ->and(array_map(fn (DaemonKind $kind): string => $kind->value, DaemonKind::activeKinds()))->toBe(array_values($roles));

    if ($file === 'docker-compose.prod.yml') {
        expect($services['postgres']['image'])->toBe('agelto/postgres:daemon-contract')
            ->and($services['postgres'])->not->toHaveKey('build')
            ->and($services)->toHaveKeys(['redis', 'redis-cache', 'redis-session'])
            ->and($app['environment']['REDIS_CACHE_HOST'])->toBe('redis-cache')
            ->and($app['environment']['REDIS_SESSION_HOST'])->toBe('redis-session')
            ->and($app['environment']['SESSION_CONNECTION'])->toBe('session')
            ->and($app['environment']['READINESS_SCHEDULER_CACHE_STORE'])->toBe('readiness')
            ->and($app['security_opt'])->toBe(['no-new-privileges:true'])
            ->and($services['doctor']['security_opt'])->toBe(['no-new-privileges:true'])
            ->and($services['horizon']['security_opt'])->toBe(['no-new-privileges:true'])
            ->and($services['scheduler']['security_opt'])->toBe(['no-new-privileges:true']);
    }

    foreach ($roles as $serviceName => $role) {
        $service = $services[$serviceName];
        if ($file === 'docker-compose.prod.yml') {
            expect($service['security_opt'])->toBe(['no-new-privileges:true']);
        }
        expect($service['image'])->toBe($app['image'])
            ->and($service['command'])->toBe(['php', 'artisan', 'process:daemon', $role])
            ->and($service['entrypoint'])->toBe(['/usr/local/bin/docker-php-serversideup-entrypoint'])
            ->and($service['working_dir'])->toBe('/var/www/html')
            ->and($service['restart'])->toBe('unless-stopped')
            ->and($service['stop_signal'])->toBe('SIGTERM')
            ->and($service['stop_grace_period'])->toBe('60s')
            ->and($service['healthcheck'])->toBe([
                'test' => ['CMD', 'php', 'artisan', 'process:daemon', $role, '--health'],
                'interval' => '10s', 'timeout' => '10s', 'retries' => 3, 'start_period' => '40s',
            ])
            ->and($service['environment'])->toBe($app['environment'])
            ->and($service['secrets'])->toBe($app['secrets'])
            ->and($service['networks'])->toBe($app['networks'])
            ->and($service['volumes'])->toBe($volumes)
            ->and($service)->not->toHaveKeys(['build', 'ports', 'profiles', 'privileged', 'network_mode'])
            ->and($service['depends_on'])->toBe([
                'app' => ['condition' => 'service_healthy'],
                'postgres' => ['condition' => 'service_healthy'],
                'redis' => ['condition' => 'service_healthy'],
            ]);
    }

    $policy = app(DaemonPolicy::class);
    expect([$policy->maxRuntimeSeconds, $policy->memoryLimitMb, $policy->heartbeatSeconds, $policy->liveSeconds])
        ->toBe([3600, 256, 10, 30])
        ->and((int) rtrim($services['horizon']['stop_grace_period'], 's'))->toBeGreaterThanOrEqual(370);
})->with([
    'development' => ['docker-compose.yml', 'agelto_daemon_contract-app', [
        './:/var/www/html',
        'app_bootstrap_cache:/var/www/html/bootstrap/cache',
        'app_storage_framework:/var/www/html/storage/framework',
    ]],
    'production' => ['docker-compose.prod.yml', 'agelto/app:daemon-contract', ['app_storage:/var/www/html/storage']],
]);
