<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\DaemonKind;
use App\Services\Procurement\Process\DaemonPolicy;
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
        ->and(array_map(fn (DaemonKind $kind): string => $kind->value, DaemonKind::activeKinds()))->toBe(array_values($roles));

    foreach ($roles as $serviceName => $role) {
        $service = $services[$serviceName];
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
