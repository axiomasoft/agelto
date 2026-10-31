<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\Architecture\PlatformSourceRules;

/** @return array<string, string> */
function platformArchitectureSources(): array
{
    $sources = [];

    foreach (['app', 'config', 'database/factories', 'database/seeders', 'database/migrations', 'tests/Support/Platforms'] as $root) {
        foreach (Finder::create()->files()->in(base_path($root))->name('*.php') as $file) {
            $path = $root.'/'.$file->getRelativePathname();
            $sources[$path] = $file->getContents();
        }
    }

    ksort($sources, SORT_STRING);

    return $sources;
}

test('T33 Platforms layout совпадает с путями и явным fixture registry во всех слоях', function (): void {
    $codes = array_values(require base_path('tests/Fixtures/Platforms/registry.php'));
    $violations = [];
    $count = 0;

    foreach (platformArchitectureSources() as $path => $source) {
        if (preg_match('~^(?:app/[^/]+|database/(?:factories|seeders)|tests/Support)/Platforms/~', $path) === 1) {
            $count++;
            $violations = [...$violations, ...PlatformSourceRules::layoutViolations(path: $path, source: $source, codes: $codes)];
        }
    }

    expect($count)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});

test('T33 dependency baseline содержит ровно существующие legacy пары и сокращается', function (): void {
    $baseline = require __DIR__.'/platforms-dependency-baseline.php';
    $keys = array_map(static fn (array $entry): string => $entry['file'].' -> '.$entry['import'], $baseline);
    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect($keys)->toBe($sorted)
        ->and(count(array_unique($keys)))->toBe(count($keys));

    foreach ($baseline as $entry) {
        expect($entry['owner'])->toBeIn(['P2.6', 'P2.18', 'P3.17']);
    }

    expect(PlatformSourceRules::dependencyViolations(sources: platformArchitectureSources(), baseline: $baseline))->toBe([]);
});

test('T33 forbidden dependency и удалённая baseline пара дают file/import диагностику', function (): void {
    $path = 'app/Services/Platforms/JsonProcurement/Csv/Parser.php';
    $import = 'App\Services\Procurement\Proxy\ProxyResolver';
    $source = '<?php namespace App\Services\Platforms\JsonProcurement\Csv; use '.$import.';';
    $fixture = tempnam(sys_get_temp_dir(), 'platform-direction-');

    try {
        file_put_contents($fixture, $source);
        $sources = [$path => file_get_contents($fixture)];
        $baseline = [['file' => $path, 'import' => $import, 'owner' => 'P2.6']];

        expect(PlatformSourceRules::dependencyViolations(sources: $sources, baseline: []))
            ->toBe(['forbidden dependency: '.$path.' -> '.$import])
            ->and(PlatformSourceRules::dependencyViolations(sources: $sources, baseline: $baseline))->toBe([])
            ->and(PlatformSourceRules::dependencyViolations(sources: [$path => '<?php'], baseline: $baseline))
            ->toBe(['stale baseline: '.$path.' -> '.$import]);
    } finally {
        unlink($fixture);
    }
});

test('T33 scanner видит use absolute FQCN и оба варианта escaping class strings', function (): void {
    $import = 'App\Services\Procurement\Proxy\ProxyResolver';
    $path = 'app/Services/Platforms/JsonProcurement/Csv/Parser.php';
    $expected = ['forbidden dependency: '.$path.' -> '.$import];

    foreach ([
        '<?php use '.$import.';',
        '<?php new \\'.$import.';',
        "<?php \$class = '".$import."';",
        "<?php \$class = '\\".$import."';",
        "<?php \$class = '".str_replace('\\', '\\\\', $import)."';",
        '<?php $class = "'.str_replace('\\', '\\\\', $import).'";',
    ] as $source) {
        expect(PlatformSourceRules::dependencyViolations(sources: [$path => $source], baseline: []))->toBe($expected);
    }

    expect(PlatformSourceRules::dependencyViolations(
        sources: [$path => '<?php use App\Services\Procurement\Proxy\{ProxyResolver};'], baseline: [],
    ))->toBe(['group use is not supported: '.$path]);

    $interpolation = '<?php namespace App\Services\Platforms\JsonProcurement\Csv; class Parser { public function run($value) { $message = "{$value}"; return function () use ($value) { return $value; }; } }';
    expect(PlatformSourceRules::inspect($interpolation)['group_use'])->toBeFalse();
});

test('T33 cross-platform dependency запрещена даже через legacy baseline', function (): void {
    $path = 'app/Services/Platforms/JsonProcurement/Csv/Parser.php';
    $import = 'App\Services\Platforms\Eis\Csv\EisCsvParser';
    $baseline = [['file' => $path, 'import' => $import, 'owner' => 'P2.12']];

    expect(PlatformSourceRules::dependencyViolations(
        sources: [$path => '<?php use '.$import.';'], baseline: $baseline,
    ))->toContain('cross-platform dependency: '.$path.' -> '.$import);
});

test('T33 exact roles не разрешают модели и repositories другим parser или UI процессные writes', function (): void {
    $model = 'App\Models\Procurement\Crawler\Crawler';
    $source = '<?php use '.$model.';';
    $ui = 'app/Filament/Platforms/Eis/EisCrawlerPlatformForm.php';
    $parser = 'app/Services/Platforms/Eis/Card/DocumentsParser.php';

    expect(PlatformSourceRules::dependencyViolations(sources: [$ui => $source], baseline: []))->toBe([])
        ->and(PlatformSourceRules::dependencyViolations(sources: [$parser => $source], baseline: []))
        ->toBe(['forbidden dependency: '.$parser.' -> '.$model])
        ->and(PlatformSourceRules::dependencyViolations(sources: [$ui => $source.' Crawler::query()->update([]);'], baseline: []))
        ->toBe(['UI process write: '.$ui]);

    $repository = 'App\Repositories\Procurement\Platform\Okpd2NodeStoreRepository';
    $synchronizer = 'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php';

    expect(PlatformSourceRules::dependencyViolations(sources: [$synchronizer => '<?php use '.$repository.';'], baseline: []))->toBe([])
        ->and(PlatformSourceRules::dependencyViolations(sources: [$parser => '<?php use '.$repository.';'], baseline: []))
        ->toBe(['forbidden dependency: '.$parser.' -> '.$repository]);
});

test('T33 layout отклоняет чужой code неправильный namespace и лишний service subdir', function (): void {
    $path = 'app/Services/Platforms/JsonProcurement/Csv/Parser.php';
    $source = '<?php namespace App\Services\Platforms\JsonProcurement\Csv;';

    expect(PlatformSourceRules::layoutViolations(path: $path, source: $source, codes: ['JsonProcurement']))->toBe([])
        ->and(PlatformSourceRules::platformCode('app/Filament/Resources/Procurement/Platform/Platforms/Pages/CreatePlatform.php'))->toBeNull()
        ->and(PlatformSourceRules::layoutViolations(path: $path, source: $source, codes: ['Eis']))->not->toBe([])
        ->and(PlatformSourceRules::layoutViolations(path: $path, source: '<?php namespace Wrong;', codes: ['JsonProcurement']))->not->toBe([])
        ->and(PlatformSourceRules::layoutViolations(
            path: 'app/Services/Platforms/JsonProcurement/Csv/Nested/Parser.php', source: $source, codes: ['JsonProcurement'],
        ))->not->toBe([]);
});

test('T33 старые FQCN и физические move paths отсутствуют а fixture bytes сохранены', function (): void {
    $map = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/platforms-move-map.json')), associative: true, flags: JSON_THROW_ON_ERROR);
    $sources = platformArchitectureSources();

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
        $sources['tests/'.$file->getRelativePathname()] = $file->getContents();
    }

    $dependencies = [];

    foreach ($sources as $source) {
        $dependencies = [...$dependencies, ...PlatformSourceRules::inspect($source)['dependencies']];
    }

    foreach ($map['moves'] as $move) {
        expect(is_file(base_path($move['old'])))->toBeFalse()
            ->and(is_file(base_path($move['new'])))->toBe(! isset($move['removed_in']));

        if (str_starts_with($move['old'], 'tests/Fixtures/')) {
            expect(hash_file('sha256', base_path($move['new'])))->toBe($move['before_sha256']);

            continue;
        }

        foreach (['app/' => 'App/', 'database/factories/' => 'Database/Factories/', 'database/seeders/' => 'Database/Seeders/', 'tests/Support/' => 'Tests/Support/'] as $prefix => $namespace) {
            if (str_starts_with($move['old'], $prefix)) {
                $old = str_replace('/', '\\', $namespace.substr($move['old'], strlen($prefix), -4));
                expect($dependencies)->not->toContain($old);
            }
        }
    }
});

test('T33 каждый перенесённый class enum factory и seeder доступен через autoload', function (): void {
    $map = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/platforms-move-map.json')), associative: true, flags: JSON_THROW_ON_ERROR);
    $count = 0;

    foreach ($map['moves'] as $move) {
        foreach (['app/' => 'App/', 'database/factories/' => 'Database/Factories/', 'database/seeders/' => 'Database/Seeders/', 'tests/Support/' => 'Tests/Support/'] as $prefix => $namespace) {
            if (str_starts_with($move['new'], $prefix)) {
                $class = str_replace('/', '\\', $namespace.substr($move['new'], strlen($prefix), -4));
                expect(class_exists($class) || enum_exists($class))->toBe(! isset($move['removed_in']));
                $count++;
            }
        }
    }

    expect($count)->toBe(24);
});

test('T33 profile preparation role cannot acquire execution persistence dependencies', function (): void {
    $violations = PlatformSourceRules::dependencyViolations([
        'app/Services/Platforms/Eis/Listing/EisProfilePreparer.php' => '<?php use App\\Repositories\\Procurement\\Process\\WorkStoreRepository;',
    ], []);
    expect($violations)->toContain('forbidden dependency: app/Services/Platforms/Eis/Listing/EisProfilePreparer.php -> App\\Repositories\\Procurement\\Process\\WorkStoreRepository');
});

test('T33 diagnostic assessment cannot select routes or read acquisition models', function (): void {
    $path = 'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php';
    foreach (['App\\Models\\Procurement\\Crawler\\Crawler', 'App\\Services\\Procurement\\Proxy\\ProxyResolver'] as $dependency) {
        expect(PlatformSourceRules::dependencyViolations([$path => '<?php use '.$dependency.';'], []))
            ->toBe(['forbidden dependency: '.$path.' -> '.$dependency]);
    }
});
