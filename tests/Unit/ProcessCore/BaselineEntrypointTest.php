<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function processCoreBaselineFixture(): string
{
    $root = sys_get_temp_dir().'/core-baseline-entrypoint-'.bin2hex(random_bytes(8));
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $runtime = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/runtime-inputs.json')), true, flags: JSON_THROW_ON_ERROR);
    $schema = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    $paths = [
        'tests/Support/ProcessCore/verify-baseline.php',
        'tests/Support/ProcessCore/BaselineVerifier.php',
        'tests/Support/ProcessCore/SchemaInventory.php',
        'tests/Support/ProcessCore/schema-inventory.json',
        'tests/Fixtures/ProcessCore/manifest.json',
        'tests/Fixtures/ProcessCore/runtime-inputs.json',
        'docs/dev/process-schema.md',
        'docs/dev/runbooks/process-test-rig.md',
        ...array_column([...$manifest['files'], ...$manifest['migrations'], ...$manifest['source_classes'], ...$manifest['contract_fixtures']], 'path'),
        ...array_column($schema['migrations'], 'path'),
        ...array_column($runtime['files'], 'source'),
    ];

    foreach (array_unique($paths) as $path) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), permissions: 0755, recursive: true);
        }

        copy(base_path($path), $root.'/'.$path);
    }

    return $root;
}

function runProcessCoreBaseline(string $root): Process
{
    $process = new Process([PHP_BINARY, $root.'/tests/Support/ProcessCore/verify-baseline.php']);
    $process->mustRun();

    return $process;
}

test('baseline entrypoint проверяет изменение и потерю каждого файла без scenarios', function (): void {
    $root = processCoreBaselineFixture();
    $manifest = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = array_filter($manifest['files'], static fn (array $entry): bool => ! array_key_exists('scenarios', $entry));

    try {
        expect($entries)->not->toBeEmpty()
            ->and(json_decode(runProcessCoreBaseline($root)->getOutput(), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['baseline_violations' => []]);

        foreach ($entries as $entry) {
            $path = $root.'/'.$entry['path'];
            $original = file_get_contents($path);

            try {
                file_put_contents($path, $original."\n// F1 changed fixture bytes\n");

                foreach (['changed', 'missing'] as $mutation) {
                    if ($mutation === 'missing') {
                        unlink($path);
                    }

                    $process = new Process([PHP_BINARY, $root.'/tests/Support/ProcessCore/verify-baseline.php']);
                    $process->run();

                    expect($process->getExitCode())->toBe(1, $entry['path'].' '.$mutation)
                        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
                        ->toBe(['baseline_violations' => [$entry['path']]]);
                }
            } finally {
                file_put_contents($path, $original);
            }
        }
    } finally {
        $this->app->make('files')->deleteDirectory($root);
    }
});

test('четыре принятых baseline pin сохраняют исходную provenance и точные accepted hashes', function (string $path, string $originalHash, string $acceptedHash): void {
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry = array_column($manifest['files'], null, 'path')[$path];

    expect($manifest['captured_before'])->toBe('P4.1 harness; no namespace move')
        ->and($entry['original_sha256'])->toBe($originalHash)
        ->and($entry['sha256'])->toBe($acceptedHash)
        ->and(hash_file('sha256', base_path($path)))->toBe($acceptedHash)
        ->and($entry['accepted_change_item'])->toBe('P4.1')
        ->and($entry['accepted_change_commit'])->toBe('943b0d6d781a9180a438c97eefa4a5dea8dcf7bb');
})->with([
    'tests/bootstrap.php' => ['tests/bootstrap.php', 'd9c814de51ac4b71937fe42007f26e4a3be7f000da26e620eab02a595215aa32', '7632503a56f874595293ff5662f1c2ec782ebee06bf09d556ffe8fb73f21fcb3'],
    'tests/Pest.php' => ['tests/Pest.php', '1e8cec593278050dd4e39e9383e6f22ef24c1c63f1353f91243d9d1fcce819d5', '3ffa7e188a445c6ad72641da2ec3fe4dea21d00de03f1ae1c6e3eb7677bacc9d'],
    'tests/Support/TestEnvironmentGuard.php' => ['tests/Support/TestEnvironmentGuard.php', '20a3f3e574c86ecc7740d1a1ef5f05efef30dfe43ce451ee3826cd5ec62ca7d9', 'cb94186c787ede953c39c2b64ed36c435ad8242ef49bcfa053bdbbeb0dfb2de6'],
    'tests/Support/TestFilesystemIsolation.php' => ['tests/Support/TestFilesystemIsolation.php', '415bb19282113a43ec88935ace300b5fefcd859d7a06b54fdac2cf6853b64748', 'd390cdd896ac3d48b55e4a634837e4fd40bc559c7f9be7c080b0b7897cd0a7ff'],
]);
