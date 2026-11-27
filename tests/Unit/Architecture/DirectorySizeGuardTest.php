<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Предотвращает разрастание каталогов `app/` («свалка из десятков файлов в одной папке»).
 * Исключения допустимы только при жёстком ограничении внешних пакетов или замороженных миграций.
 */
test('каждый каталог под app содержит не более 25 прямых PHP-файлов кроме документированных исключений', function (): void {
    $maxDirectFiles = 25;
    $exemptions = [
        // AzGuard PanelDiscovery связывает Permissions/<group>/*Permission с Policies/<group>/*Policy по относительному пути.
        'app/Guards/Admin/Permissions',
        // Legacy-модели переезда Proxyma закреплены по точному namespace в SHA-256-миграциях (schema-inventory.json).
        'app/Models/Procurement/Proxyma',
    ];

    $counts = [];
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relDir = 'app/'.trim(str_replace('\\', '/', $file->getRelativePath()), '/');
        $relDir = rtrim($relDir, '/');
        $counts[$relDir] = ($counts[$relDir] ?? 0) + 1;
    }

    $violations = [];
    foreach ($counts as $dir => $count) {
        if ($count > $maxDirectFiles && ! in_array($dir, $exemptions, true)) {
            $violations[] = sprintf('%s: %d прямых PHP-файлов (лимит %d).', $dir, $count, $maxDirectFiles);
        }
    }

    expect($violations)->toBe([]);
});
