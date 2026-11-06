<?php

declare(strict_types=1);

use App\Doctor\Diagnostics\ApplicationReadinessIsGreen;
use App\Doctor\Diagnostics\BaseDataIsReady;
use App\Doctor\Diagnostics\InitialAdminIsReady;
use App\Doctor\Diagnostics\PrivatePackagesArePinned;
use App\Doctor\Diagnostics\SecretContractIsReady;
use App\Services\Health\ApplicationReadiness;
use App\Services\Health\ReleaseStateReadiness;
use App\Services\Llm\PromptCanonSynchronizer;
use App\Services\Procurement\Process\DaemonReadiness;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Process;
use Laravel\Doctor\Results\Status;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

test('SecretContractIsReady проходит при заполненных секретах и возвращает fail при пустом секрете', function (): void {
    config([
        'app.key' => 'base64:test-key',
        'database.connections.pgsql.password' => 'pg-secret',
        'database.redis.default.password' => 'redis-secret',
        'filesystems.disks.s3.secret' => 's3-secret',
    ]);

    $diagnostic = new SecretContractIsReady;
    $passed = $diagnostic->check();
    expect($passed->status)->toBe(Status::Pass)
        ->and($passed->code)->toBe('secret-contract-is-ready.ready');

    config(['filesystems.disks.s3.secret' => '   ']);
    $failed = $diagnostic->check();
    expect($failed->status)->toBe(Status::Fail)
        ->and($failed->code)->toBe('secret-contract-is-ready.missing');
});

test('PrivatePackagesArePinned проверяет закрытый манифест и результат vendor-sync вне production', function (): void {
    Process::fake([
        '*vendor-sync.sh*' => Process::result(output: 'ok', exitCode: 0),
    ]);

    $diagnostic = new PrivatePackagesArePinned;
    $result = $diagnostic->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->code)->toBe('private-packages-are-pinned.pinned');

    Process::fake([
        '*vendor-sync.sh*' => Process::result(errorOutput: 'drift', exitCode: 1),
    ]);

    $failed = $diagnostic->check();
    expect($failed->status)->toBe(Status::Fail)
        ->and($failed->code)->toBe('private-packages-are-pinned.missing');
});

test('InitialAdminIsReady и BaseDataIsReady формируют корректные коды результатов и сообщения диагностики', function (): void {
    config(['cms.admin_email' => '']);

    $releaseReadiness = new ReleaseStateReadiness(app(PromptCanonSynchronizer::class));
    $initialAdmin = new InitialAdminIsReady($releaseReadiness);
    $adminResult = $initialAdmin->check();

    expect($adminResult->status)->toBe(Status::Fail)
        ->and($adminResult->code)->toBe('initial-admin-is-ready.missing')
        ->and($adminResult->summary)->toContain('Initial SuperAdmin is missing');

    $baseData = new BaseDataIsReady($releaseReadiness);
    $messagesMethod = new ReflectionMethod($baseData, 'messages');
    expect($messagesMethod->invoke($baseData))->toHaveKeys(['ready', 'missing'])
        ->and($baseData->group)->toBe('agelto-release');
});

test('ApplicationReadinessIsGreen возвращает degraded при недоступности зависимостей readiness', function (): void {
    $db = Mockery::mock(ConnectionResolverInterface::class);
    $db->shouldReceive('connection')->andThrow(new RuntimeException('db offline'));

    $readiness = new ApplicationReadiness(
        database: $db,
        cache: app(CacheFactory::class),
        filesystem: app(FilesystemFactory::class),
        masters: Mockery::mock(MasterSupervisorRepository::class),
        releaseState: new ReleaseStateReadiness(app(PromptCanonSynchronizer::class)),
        daemons: app(DaemonReadiness::class),
    );

    $diagnostic = new ApplicationReadinessIsGreen($readiness);
    $result = $diagnostic->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->code)->toBe('application-readiness-is-green.degraded')
        ->and($diagnostic->group)->toBe('agelto-readiness');
});
