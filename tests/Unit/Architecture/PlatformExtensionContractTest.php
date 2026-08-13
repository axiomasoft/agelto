<?php

declare(strict_types=1);

test('source-specific dispatch остаётся внутри adapter, config, seed или explicit fixture', function (): void {
    $genericRoots = [
        app_path('Services/Procurement/SourceMethodFactory.php'),
        app_path('Services/Procurement/CrawlerFilterFactory.php'),
        app_path('Services/Procurement/PlatformAdapterRegistry.php'),
        app_path('Services/Procurement/StatusMappingService.php'),
        app_path('Models/Procurement/Crawler/Crawler.php'),
        app_path('Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php'),
    ];
    $violations = [];

    foreach ($genericRoots as $path) {
        if (preg_match('/\b(Eis|fz44)\b/', (string) file_get_contents($path)) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([])
        ->and(preg_match('/\b(Eis|fz44)\b/', 'if ($platform === "eis") { return "fz44"; }'))->toBe(1);
});
