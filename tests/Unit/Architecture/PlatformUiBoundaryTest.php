<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

pest()->group('platform-ui-boundary');

function platformUiImportsImplementation(string $source): bool
{
    // Both PHP names and escaped class-string literals; aliases retain their qualified import.
    $source = str_replace('\\\\', '\\', $source);

    return preg_match('/App\\\\(?:Filament|Models|Services|Dto|Enums)\\\\Platforms\\\\/', $source) === 1
        || preg_match('/^\s*use\s+[^;{]+\{/m', $source) === 1;
}

test('generic procurement resources импортируют только optional UI seam', function (): void {
    foreach (Finder::create()->files()->name('*.php')->in(app_path('Filament/Resources/Procurement/Procurements')) as $file) {
        expect(platformUiImportsImplementation((string) file_get_contents($file->getPathname())), $file->getPathname())->toBeFalse();
    }
});

test('UI guard обнаруживает forbidden import и fully qualified class string', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'platform-ui-');
    if ($path === false) {
        throw new RuntimeException('Cannot create negative source fixture.');
    }
    try {
        foreach ([
            '<?php use App\\Models\\Platforms\\Fixture\\Details;',
            '<?php $extension = \\App\\Filament\\Platforms\\Fixture\\Ui::class;',
            '<?php $class = "App\\\\Services\\\\Platforms\\\\Fixture\\\\Source";',
            "<?php\nuse App\\Models\\{Platforms\\Fixture\\Details};",
        ] as $source) {
            file_put_contents($path, $source);
            expect(platformUiImportsImplementation((string) file_get_contents($path)))->toBeTrue();
        }
        expect(platformUiImportsImplementation('<?php use App\\Services\\Procurement\\Platform\\PlatformUiResolver;'))->toBeFalse();
    } finally {
        unlink($path);
    }
});
