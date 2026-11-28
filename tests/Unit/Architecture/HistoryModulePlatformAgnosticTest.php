<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

test('модуль истории не зависит от платформенного адаптера или сырого этапа', function (): void {
    $sources = Finder::create()
        ->files()
        ->in([app_path('Services/Procurement/History'), app_path('Repositories/Procurement/History')])
        ->name('*.php')
        ->getIterator();

    foreach ($sources as $source) {
        $contents = (string) file_get_contents($source->getRealPath());

        expect($contents)->not->toContain('Eis')
            ->not->toContain('source_stage');
    }
});
