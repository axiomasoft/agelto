<?php

declare(strict_types=1);

use App\Doctor\Diagnostics\PrivatePackagesArePinned;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Doctor\Results\Status;

test('принимает актуальный манифест и передаёт Git точные пути обоих репозиториев', function (): void {
    Process::fake(['*vendor-sync.sh*' => Process::result(exitCode: 0)]);

    $result = (new PrivatePackagesArePinned)->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->code)->toBe('private-packages-are-pinned.pinned');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'bash', base_path(path: 'docker/scripts/vendor-sync.sh'), '--check',
    ] && $process->environment === [
        'GIT_CONFIG_COUNT' => '2',
        'GIT_CONFIG_KEY_0' => 'safe.directory',
        'GIT_CONFIG_VALUE_0' => base_path(path: 'local/vendors/azguard'),
        'GIT_CONFIG_KEY_1' => 'safe.directory',
        'GIT_CONFIG_VALUE_1' => base_path(path: 'local/vendors/proxyma'),
    ]);
});

test('отклоняет невалидную копию манифеста до запуска проверки репозиториев', function (string $field, mixed $value): void {
    $app = app();
    $originalRoot = $app->basePath();
    $manifest = json_decode(
        json: File::get(base_path(path: 'docker/private-vendors.lock.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    data_set(target: $manifest, key: $field, value: $value);

    $root = sys_get_temp_dir().'/vendor-lock-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists(path: "{$root}/docker", mode: 0700);

    try {
        File::put(path: "{$root}/docker/private-vendors.lock.json", contents: json_encode($manifest, JSON_THROW_ON_ERROR));
        $app->setBasePath($root);
        Process::fake();

        $result = (new PrivatePackagesArePinned)->check();

        expect($result->status)->toBe(Status::Fail)
            ->and($result->code)->toBe('private-packages-are-pinned.missing');
        Process::assertNothingRan();
    } finally {
        $app->setBasePath($originalRoot);
        File::deleteDirectory($root);
    }
})->with([
    'устаревшая версия' => ['version', 1],
    'лишнее поле' => ['extra', true],
    'пустой список репозиториев' => ['repositories', []],
    'повтор ключа репозитория' => ['repositories.1.key', 'azguard'],
    'выход из каталога репозиториев' => ['repositories.1.key', '../proxyma'],
    'подмена origin' => ['repositories.1.repository.path', 'someone/proxyma'],
    'неполный SHA' => ['repositories.1.commit', 'a61d7b9'],
    'лишнее поле пакета' => ['repositories.1.packages.0.extra', true],
    'недостающий пакет' => ['repositories.1.packages', []],
    'выход из каталога пакетов' => ['repositories.1.packages.0.path', 'packages/..'],
    'подмена имени пакета' => ['repositories.1.packages.1.name', 'axiomasoft/azguard-filament'],
    'подмена версии пакета' => ['repositories.1.packages.0.version', 'dev-main'],
]);

test('проверяет наличие каждого пакета из обоих репозиториев в установке', function (): void {
    $app = app();
    $originalRoot = $app->basePath();
    $manifest = File::get(base_path(path: 'docker/private-vendors.lock.json'));
    $diagnostic = new PrivatePackagesArePinned;
    $repositories = (new ReflectionMethod($diagnostic, 'readClosedManifest'))->invoke($diagnostic, $manifest);
    $packagesAreInstalled = new ReflectionMethod($diagnostic, 'packagesAreInstalled');
    $root = sys_get_temp_dir().'/vendor-install-'.bin2hex(random_bytes(8));
    $directories = ['azguard', 'azguard-filament', 'proxyma', 'proxyma-filament'];

    try {
        $app->setBasePath($root);
        foreach ($directories as $directory) {
            File::ensureDirectoryExists(path: "{$root}/vendor/axiomasoft/{$directory}", mode: 0700);
        }
        expect($packagesAreInstalled->invoke($diagnostic, $repositories))->toBeTrue();

        foreach ($directories as $directory) {
            $path = "{$root}/vendor/axiomasoft/{$directory}";
            File::deleteDirectory($path);
            expect($packagesAreInstalled->invoke($diagnostic, $repositories))->toBeFalse();
            File::ensureDirectoryExists(path: $path, mode: 0700);
        }
    } finally {
        $app->setBasePath($originalRoot);
        File::deleteDirectory($root);
    }
});
