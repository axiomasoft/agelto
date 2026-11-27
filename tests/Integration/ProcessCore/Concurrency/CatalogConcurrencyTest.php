<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\NetworkFixtures;

pest()->group('source-catalog-lifecycle');

test('два concurrent sync сохраняют один origin authority и override version fence', function (): void {
    $code = 'net_'.bin2hex(random_bytes(6));
    $platform = NetworkFixtures::platform($code);
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog($code));
    $scope = CanonicalOrigin::fromUrl('https://example.test')->budgetScopeKey();
    $before = DB::table('prc_source_targets')->where('platform_id', $platform->id)->orderBy('id')->get()->all();
    $budgetCount = DB::table('prc_network_budgets')->count();
    $worker = sprintf(<<<'PHP'
        $sync = app(\App\Services\Procurement\Network\SourceCatalogSync::class);
        $result = $sync->sync(\Tests\Support\ProcessCore\NetworkFixtures::catalog('%s'));
        return ['changed' => $result->changed(), 'budgets' => $result->budgetsCreated, 'pid' => \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        PHP, $code);
    try {
        $results = ConcurrentTransactions::concurrently(['a' => $worker, 'b' => $worker]);
        expect($results['a']['pid'])->not->toBe($results['b']['pid'])
            ->and($results['a']['changed'])->toBeFalse()->and($results['b']['changed'])->toBeFalse()
            ->and($results['a']['budgets'])->toBe(0)->and($results['b']['budgets'])->toBe(0)
            ->and(DB::table('prc_network_budgets')->count())->toBe($budgetCount)
            ->and(DB::table('prc_source_targets')->where('platform_id', $platform->id)->count())->toBe(2)
            ->and(DB::table('prc_source_targets')->where('platform_id', $platform->id)->orderBy('id')->get()->all())->toEqual($before);
    } finally {
        $targets = DB::table('prc_source_targets')->where('platform_id', $platform->id)->pluck('id');
        DB::table('prc_route_target_health')->whereIn('target_id', $targets)->delete();
        DB::table('prc_target_health')->whereIn('target_id', $targets)->delete();
        DB::table('prc_source_targets')->whereIn('id', $targets)->delete();
        Platform::query()->whereKey($platform->id)->delete();
    }
});
