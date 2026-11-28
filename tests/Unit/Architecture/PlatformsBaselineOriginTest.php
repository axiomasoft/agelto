<?php

declare(strict_types=1);

use Tests\Support\Architecture\PlatformSourceRules;

pest()->group('platform-dependency-boundary');

test('historical baseline pairs are either removed or allowed by permanent exact roles', function (): void {
    $map = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/platforms-move-map.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($map['dependency_origin']['commit'])->toBe('02f41f9dbcc5e50237abcf4ee54b953d052de068');
    foreach ($map['dependency_origin']['pairs'] as $pair) {
        if (! is_file(base_path($pair['file']))) {
            continue;
        }
        expect(PlatformSourceRules::forbiddenDependencies($pair['file'], file_get_contents(base_path($pair['file']))))
            ->not->toContain($pair['import']);
    }
    expect(is_file(__DIR__.'/platforms-dependency-baseline.php'))->toBeFalse();
});

test('permanent role permissions do not grant the same import to a neighboring file', function (): void {
    foreach (PlatformSourceRules::rolePermissions() as $path => $imports) {
        foreach ($imports as $import) {
            expect(PlatformSourceRules::forbiddenDependencies($path, '<?php use '.$import.';'))->toBe([]);
        }
    }
    $path = 'app/Filament/Platforms/Eis/OtherForm.php';
    expect(PlatformSourceRules::forbiddenDependencies($path, '<?php use App\Models\Procurement\Crawler\Crawler;'))
        ->toContain('App\Models\Procurement\Crawler\Crawler');
});
