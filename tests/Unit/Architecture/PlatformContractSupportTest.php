<?php

declare(strict_types=1);

use Tests\Support\Architecture\PlatformSourceRules;

test('neutral contract kit имеет exact root namespace и не импортирует реализации площадок', function (): void {
    $path = 'tests/Support/Platforms/Contract/RecordingOutbound.php';
    $source = file_get_contents(base_path($path));
    expect(PlatformSourceRules::layoutViolations($path, $source, ['Eis', 'JsonProcurement']))->toBe([])
        ->and(PlatformSourceRules::forbiddenDependencies($path, $source))->toBe([]);
    expect(PlatformSourceRules::forbiddenDependencies($path, '<?php use Tests\Support\Platforms\JsonProcurement\JsonListingSource;'))
        ->toBe(['Tests\Support\Platforms\JsonProcurement\JsonListingSource']);
    expect(PlatformSourceRules::layoutViolations($path, '<?php namespace Wrong;', ['Eis']))->not->toBe([])
        ->and(PlatformSourceRules::layoutViolations('tests/Support/Platforms/Contract/Nested/Bad.php', '<?php', ['Eis']))->not->toBe([]);
});

test('contract registry разрешает только exact manifests а platform adapter может использовать neutral kit', function (): void {
    $registry = 'tests/Support/Platforms/Contract/ContractRegistry.php';
    $manifest = 'Tests\\Support\\Platforms\\Eis\\EisContractManifest';
    $other = 'App\\Services\\Platforms\\Eis\\EisListingSource';
    expect(PlatformSourceRules::forbiddenDependencies($registry, '<?php use '.$manifest.';'))->toBe([])
        ->and(PlatformSourceRules::forbiddenDependencies($registry, '<?php use '.$other.';'))->toBe([$other])
        ->and(PlatformSourceRules::forbiddenDependencies('tests/Support/Platforms/Contract/AdapterContractKit.php', '<?php use '.$manifest.';'))->toBe([$manifest])
        ->and(PlatformSourceRules::forbiddenDependencies('tests/Support/Platforms/JsonProcurement/JsonContractManifest.php', '<?php use Tests\\Support\\Platforms\\Contract\\ContractCase;'))->toBe([])
        ->and(PlatformSourceRules::forbiddenDependencies('tests/Support/Platforms/JsonProcurement/JsonContractManifest.php', '<?php use '.$manifest.';'))->toBe([$manifest]);
});
