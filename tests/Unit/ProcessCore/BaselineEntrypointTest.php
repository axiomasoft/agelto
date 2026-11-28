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
        'tests/Pest.php',
        ...array_column($manifest['contract_fixtures'], 'path'),
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

test('проверка фикстур принимает правки тестов и обнаруживает изменение или потерю неизменяемых данных', function (): void {
    $root = processCoreBaselineFixture();
    $manifest = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $runtime = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/runtime-inputs.json'), true, flags: JSON_THROW_ON_ERROR);
    $gold = collect($runtime['files'])->first(fn (array $entry): bool => str_starts_with($entry['source'], 'estimates/gold/'));

    $verify = function (int $exit, array $violations) use ($root): void {
        $process = new Process([PHP_BINARY, $root.'/tests/Support/ProcessCore/verify-baseline.php']);
        $process->run();

        expect($process->getExitCode())->toBe($exit, $process->getErrorOutput())
            ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['baseline_violations' => $violations]);
    };

    try {
        file_put_contents($root.'/tests/Pest.php', "\n// Правка исходника тестов.\n", FILE_APPEND);
        $verify(0, []);

        foreach ([$manifest['contract_fixtures'][0]['path'], $gold['source']] as $fixture) {
            $path = $root.'/'.$fixture;
            $original = file_get_contents($path);
            file_put_contents($path, "\nchanged fixture\n", FILE_APPEND);
            $verify(1, [$fixture]);
            unlink($path);
            $verify(1, [$fixture]);
            file_put_contents($path, $original);
        }
    } finally {
        $this->app->make('files')->deleteDirectory($root);
    }
});
