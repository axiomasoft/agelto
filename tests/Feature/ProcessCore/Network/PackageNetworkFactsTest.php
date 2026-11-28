<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\TestEnvironmentGuard;

test('до установки package read projections возвращают типизированный пустой результат', function (): void {
    TestEnvironmentGuard::assertBootstrapTestDatabase(app());
    expect(DB::transactionLevel())->toBeGreaterThan(0);
    DB::statement('DROP TABLE IF EXISTS pxm_attempts, pxm_permits, pxm_demands CASCADE');
    expect(PackageNetworkFacts::attempts()->count())->toBe(0)
        ->and(PackageNetworkFacts::permits()->whereNull('authorized_at')->count())->toBe(0)
        ->and(PackageNetworkFacts::demands()->where('operation_id', LedgerFixtures::uuid()->value)->count())->toBe(0)
        ->and(PackageNetworkFacts::attempts()->where('sent_at', '<', DB::raw('clock_timestamp()'))->count())->toBe(0);
});
