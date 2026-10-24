<?php

declare(strict_types=1);

use Tests\Support\Architecture\PlatformSourceRules;

test('T33 legacy baseline остаётся подмножеством исходных пар P4.2 с теми же owners', function (): void {
    $map = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/platforms-move-map.json')), associative: true, flags: JSON_THROW_ON_ERROR);
    $origin = $map['dependency_origin'];
    $baseline = require __DIR__.'/platforms-dependency-baseline.php';

    expect($origin['commit'])->toBe('02f41f9dbcc5e50237abcf4ee54b953d052de068')
        ->and($origin['pairs'])->not->toBeEmpty()
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: $baseline, origin: $origin['pairs']))->toBe([]);

    foreach ($baseline as $entry) {
        expect(PlatformSourceRules::rolePermissions()[$entry['file']] ?? [])->not->toContain($entry['import']);
    }
});

test('T33 добавление существующей forbidden пары и смена owner не расширяют baseline', function (): void {
    $entry = [
        'file' => 'app/Services/Platforms/JsonProcurement/Csv/Parser.php',
        'import' => 'App\Services\Procurement\Proxy\ProxyResolver',
        'owner' => 'P2.6',
    ];
    $source = '<?php use '.$entry['import'].';';
    $sources = [$entry['file'] => $source];

    expect(PlatformSourceRules::dependencyViolations(sources: $sources, baseline: [$entry]))->toBe([])
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: [$entry], origin: []))
        ->toBe(['expanded baseline or changed owner: '.$entry['file'].' -> '.$entry['import']])
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: [], origin: [$entry]))->toBe([])
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: [$entry], origin: [$entry]))->toBe([])
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: [[...$entry, 'owner' => 'P3.17']], origin: [$entry]))
        ->toBe(['expanded baseline or changed owner: '.$entry['file'].' -> '.$entry['import']])
        ->and(PlatformSourceRules::baselineGrowthViolations(baseline: [$entry, $entry], origin: [$entry]))
        ->toBe(['duplicate baseline pair: '.$entry['file'].' -> '.$entry['import']]);
});
