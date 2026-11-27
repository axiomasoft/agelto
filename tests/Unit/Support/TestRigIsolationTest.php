<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\Support\ProcessCore\BaselineVerifier;
use Tests\Support\ProcessCore\InstallClock;
use Tests\Support\ProcessCore\MinimalSeeder;
use Tests\Support\ProcessCore\PromptWorkspace;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

test('install clock фиксирует mutable и immutable время только для isolated install', function (): void {
    $rig = getenv('PROCESS_CORE_TEST');
    $install = getenv('PROCESS_CORE_INSTALL');
    $previousAnchor = getenv('PROCESS_CORE_INSTALL_AT');
    $anchor = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(4, 5, 6);

    try {
        putenv('PROCESS_CORE_TEST=1');
        putenv('PROCESS_CORE_INSTALL=1');
        putenv('PROCESS_CORE_INSTALL_AT='.$anchor->format('Y-m-d\TH:i:s\Z'));
        InstallClock::configure();
        expect(now('UTC')->toDateTimeString())->toBe($anchor->format('Y-m-d H:i:s'))
            ->and(CarbonImmutable::now('UTC')->toDateTimeString())->toBe($anchor->format('Y-m-d H:i:s'));

        foreach ([['0', '1'], ['1', '0']] as [$rigFlag, $installFlag]) {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            putenv('PROCESS_CORE_TEST='.$rigFlag);
            putenv('PROCESS_CORE_INSTALL='.$installFlag);
            InstallClock::configure();
            expect(Carbon::hasTestNow())->toBeFalse()
                ->and(CarbonImmutable::hasTestNow())->toBeFalse();
        }
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        putenv($rig === false ? 'PROCESS_CORE_TEST' : 'PROCESS_CORE_TEST='.$rig);
        putenv($install === false ? 'PROCESS_CORE_INSTALL' : 'PROCESS_CORE_INSTALL='.$install);
        putenv($previousAnchor === false ? 'PROCESS_CORE_INSTALL_AT' : 'PROCESS_CORE_INSTALL_AT='.$previousAnchor);
    }
});

test('install clock отклоняет отсутствующий, невалидный и чужой месяц до заморозки времени', function (): void {
    $keys = ['PROCESS_CORE_TEST', 'PROCESS_CORE_INSTALL', 'PROCESS_CORE_INSTALL_AT'];
    $previous = array_combine($keys, array_map(getenv(...), $keys));
    $month = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));

    try {
        putenv('PROCESS_CORE_TEST=1');
        putenv('PROCESS_CORE_INSTALL=1');

        foreach ([false, '', 'not-a-clock', '2026-02-30T00:00:00Z',
            $month->modify('-1 month')->format('Y-m-d\TH:i:s\Z'),
            $month->modify('+1 month')->format('Y-m-d\TH:i:s\Z'),
            $month->format('Y-m-d\TH:i:sP'),
        ] as $anchor) {
            putenv($anchor === false ? 'PROCESS_CORE_INSTALL_AT' : 'PROCESS_CORE_INSTALL_AT='.$anchor);
            expect(fn () => InstallClock::configure())->toThrow(RuntimeException::class, 'verified current-month UTC');
            expect(Carbon::hasTestNow())->toBeFalse()
                ->and(CarbonImmutable::hasTestNow())->toBeFalse();
        }
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
});

test('minimal seed наследует install anchor и сохраняет оба clock для всего subprocess', function (): void {
    $keys = ['PROCESS_CORE_TEST', 'PROCESS_CORE_INSTALL', 'PROCESS_CORE_INSTALL_AT'];
    $previous = array_combine($keys, array_map(getenv(...), $keys));
    $anchor = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(4, 5, 6);
    $probe = new class extends Seeder
    {
        public array $observed = [];

        public function run(): void
        {
            $this->observed = [now('UTC')->toDateTimeString(), CarbonImmutable::now('UTC')->toDateTimeString()];
        }
    };
    $this->app->instance(PlatformSeeder::class, $probe);

    try {
        putenv('PROCESS_CORE_TEST=1');
        putenv('PROCESS_CORE_INSTALL=1');
        putenv('PROCESS_CORE_INSTALL_AT='.$anchor->format('Y-m-d\TH:i:s\Z'));
        InstallClock::configure();
        (new MinimalSeeder)->setContainer($this->app)->run();

        expect($probe->observed)->toBe([$anchor->format('Y-m-d H:i:s'), $anchor->format('Y-m-d H:i:s')])
            ->and(now('UTC')->toDateTimeString())->toBe($anchor->format('Y-m-d H:i:s'))
            ->and(CarbonImmutable::now('UTC')->toDateTimeString())->toBe($anchor->format('Y-m-d H:i:s'));
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        $this->app->forgetInstance(PlatformSeeder::class);

        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
});

test('prompt workspace копирует настоящий canon и изолирует изменения и stale probes', function (): void {
    $source = resource_path('ai/prompts');
    $target = sys_get_temp_dir().'/prompt-workspace';
    mkdir($target);
    $file = 'global/0-global.md';
    $original = file_get_contents($source.'/'.$file);

    try {
        PromptWorkspace::refresh(source: $source, target: $target);
        expect(file_get_contents($target.'/'.$file))->toBe($original);
        file_put_contents($target.'/'.$file, 'test-only rewrite');
        file_put_contents($target.'/stale-probe.md', 'stale');
        expect(file_get_contents($source.'/'.$file))->toBe($original);
        PromptWorkspace::refresh(source: $source, target: $target);
        expect(file_get_contents($target.'/'.$file))->toBe($original)
            ->and(is_file($target.'/stale-probe.md'))->toBeFalse();
        expect(fn () => PromptWorkspace::refresh(source: $source, target: $source))
            ->toThrow(RuntimeException::class, 'distinct');
        symlink($source, $target.'/escape');
        expect(fn () => PromptWorkspace::refresh(source: $source, target: $target))
            ->toThrow(RuntimeException::class, 'symlinks');
        expect(file_get_contents($source.'/'.$file))->toBe($original);
    } finally {
        if (is_link($target.'/escape')) {
            unlink($target.'/escape');
        }

        $this->app->make('files')->deleteDirectory($target);
    }
});

test('process-core guard отклоняет dev host и общий Redis prefix до первого запроса', function (): void {
    config([
        'database.connections.pgsql.host' => 'core-postgres',
        'database.connections.pgsql.username' => 'core_test',
        'database.redis.default.host' => 'core-redis',
        'database.redis.cache.host' => 'core-redis',
        'database.redis.default.url' => '',
        'database.redis.cache.url' => '',
        'database.redis.options.prefix' => 'agelto_core_test:',
        'horizon.prefix' => 'agelto_core_test:horizon:',
        'queue.default' => getenv('PROCESS_CORE_QUEUE_MODE') ?: 'sync',
    ]);

    TestEnvironmentGuard::assertProcessCoreIdentity($this->app);

    foreach (['database.connections.pgsql.host' => 'postgres', 'database.redis.options.prefix' => 'agelto-database-'] as $key => $value) {
        $original = config($key);
        config([$key => $value]);

        try {
            expect(fn () => TestEnvironmentGuard::assertProcessCoreIdentity($this->app))
                ->toThrow(RuntimeException::class, 'process-core identity');
        } finally {
            config([$key => $original]);
        }
    }
});

test('baseline checker обнаруживает изменённый и потерянный исходный сценарий', function (): void {
    $root = sys_get_temp_dir().'/core-baseline-negative';
    mkdir($root);
    file_put_contents($root.'/OriginalTest.php', '<?php // original scenario');
    $entry = ['path' => 'OriginalTest.php', 'sha256' => hash_file('sha256', $root.'/OriginalTest.php')];

    try {
        expect(BaselineVerifier::violations(root: $root, entries: [$entry]))->toBe([]);
        file_put_contents($root.'/OriginalTest.php', '<?php // lost scenario');
        expect(BaselineVerifier::violations(root: $root, entries: [$entry]))->toBe(['OriginalTest.php']);
        unlink($root.'/OriginalTest.php');
        expect(BaselineVerifier::violations(root: $root, entries: [$entry]))->toBe(['OriginalTest.php']);
    } finally {
        if (is_file($root.'/OriginalTest.php')) {
            unlink($root.'/OriginalTest.php');
        }

        rmdir($root);
    }
});

test('bootstrap guard отвергает URL подключения который обходит canonical database', function (): void {
    config(['database.connections.pgsql.url' => 'postgres://dev/another_test']);

    expect(fn () => TestEnvironmentGuard::assertBootstrapTestDatabase($this->app))
        ->toThrow(RuntimeException::class, 'пустой DB_URL');
});

test('process-core guard отвергает несовпадающий режим реальной очереди', function (): void {
    config([
        'database.connections.pgsql.host' => 'core-postgres',
        'database.connections.pgsql.username' => 'core_test',
        'database.redis.default.host' => 'core-redis',
        'database.redis.cache.host' => 'core-redis',
        'database.redis.default.url' => '',
        'database.redis.cache.url' => '',
        'database.redis.options.prefix' => 'agelto_core_test:',
        'horizon.prefix' => 'agelto_core_test:horizon:',
        'queue.default' => (getenv('PROCESS_CORE_QUEUE_MODE') ?: 'sync') === 'redis' ? 'sync' : 'redis',
    ]);

    expect(fn () => TestEnvironmentGuard::assertProcessCoreIdentity($this->app))
        ->toThrow(RuntimeException::class, 'queue mode');
});

test('filesystem guard отвергает невалидный process token до записи', function (string $token): void {
    expect(fn () => TestFilesystemIsolation::prepare(process: $token))
        ->toThrow(RuntimeException::class, 'token');
})->with(['../outside', 'worker/other', '', '..']);

test('runtime cleaner отказывается очищать чужой root даже при подмене env', function (): void {
    $root = sys_get_temp_dir().'/foreign-runtime';
    mkdir($root.'/tests/Support/ProcessCore', permissions: 0755, recursive: true);
    mkdir($root.'/storage/app/private', permissions: 0755, recursive: true);
    copy(base_path('tests/Support/ProcessCore/clean-runtime.php'), $root.'/tests/Support/ProcessCore/clean-runtime.php');
    copy(base_path('tests/Support/TestFilesystemIsolation.php'), $root.'/tests/Support/TestFilesystemIsolation.php');
    file_put_contents($root.'/storage/app/private/marker', 'foreign');

    try {
        $process = new Process(['php', $root.'/tests/Support/ProcessCore/clean-runtime.php'], env: [
            'PROCESS_CORE_TEST' => '1',
            'APP_ENV' => 'testing',
            'DB_HOST' => 'core-postgres',
            'DB_DATABASE' => 'agelto_test',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('isolated testing project')
            ->and(file_get_contents($root.'/storage/app/private/marker'))->toBe('foreign');
    } finally {
        $this->app->make('files')->deleteDirectory($root);
    }
});

test('filesystem guard отвергает удалённый диск после конфигурации', function (): void {
    config(['filesystems.disks.s3.driver' => 's3']);

    expect(fn () => TestEnvironmentGuard::assertIsolatedTestFilesystem($this->app))
        ->toThrow(RuntimeException::class, 'filesystem disk');
});

test('prepare и cleanup не переходят по symlink в чужой process-root', function (): void {
    $owned = TestFilesystemIsolation::prepare(process: 'core_guard_owned');
    $link = storage_path('framework/testing/core_guard_link');
    file_put_contents($owned.'/artifacts/marker', 'keep');
    symlink($owned, $link);

    try {
        expect(fn () => TestFilesystemIsolation::prepare(process: 'core_guard_link'))
            ->toThrow(RuntimeException::class, 'symlink');
        expect(fn () => TestFilesystemIsolation::cleanup(process: 'core_guard_link'))
            ->toThrow(RuntimeException::class, 'symlink');
        expect(file_get_contents($owned.'/artifacts/marker'))->toBe('keep');
    } finally {
        unlink($link);
        TestFilesystemIsolation::cleanup(process: 'core_guard_owned');
    }
});
