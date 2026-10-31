<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use Tests\Support\ProcessCore\ListingFixtures as F;

test('обновление, попавшее в стык окон, не теряется — перекрытие расширяет рескан назад', function (): void {
    F::bind();
    $previous = F::run('2024-01-01');
    F::finish($previous['work'], $previous['input'], F::page());
    $pass = F::next($previous['crawl']) ?? throw new LogicException('Missing stable pass');
    F::finish($pass['work'], $pass['input'], F::page());
    $coverage = app(ListingCoverageRepository::class)->read($previous['stream']);
    expect($coverage->verifiedThrough)->toBe('2024-01-01')
        ->and($coverage->resumeFrom('2023-01-01', 2))->toBe('2023-12-30');
    $registry = Registry::query()->sole();
    $recovery = F::run($coverage->resumeFrom('2023-01-01', 2), '2024-01-03',
        stream: $previous['stream'], registry: $registry);
    $iterations = 0;
    while (($next = F::next($recovery['crawl'])) !== null) {
        expect(++$iterations)->toBeLessThanOrEqual(10);
        $task = app(ListingStoreRepository::class)->task($next['input']);
        F::finish($next['work'], $next['input'], F::page([F::summary('boundary-'.$task->windowStart)]));
    }
    expect($iterations)->toBe(10)->and(Procurement::query()->count())->toBe(5)
        ->and(Procurement::query()->where('source_identity_value', 'boundary-2023-12-30')->exists())->toBeTrue()
        ->and(Procurement::query()->where('source_identity_value', 'boundary-2023-12-31')->exists())->toBeTrue()
        ->and(app(ListingCoverageRepository::class)->read($previous['stream'])->verifiedThrough)->toBe('2024-01-03');
});
