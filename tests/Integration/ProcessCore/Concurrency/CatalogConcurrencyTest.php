<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\CanonicalOrigin;
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
    $version = (int) DB::table('prc_network_budgets')->where('scope_key', $scope)->sole()->policy_version;
    $worker = sprintf(<<<'PHP'
        $sync = app(\App\Services\Procurement\Network\SourceCatalogSync::class);
        $sync->sync(\Tests\Support\ProcessCore\NetworkFixtures::catalog('%s'));
        $result = $sync->overrideBudget('%s', new \App\Dto\Procurement\Platform\OriginBudgetPolicy(11, 1, 2), %d, null, 'concurrent override');
        return ['status' => $result->status->value, 'pid' => \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        PHP, $code, $scope, $version);
    try {
        $results = ConcurrentTransactions::concurrently(['a' => $worker, 'b' => $worker]);
        $states = [$results['a']['status'], $results['b']['status']];
        sort($states);
        expect($results['a']['pid'])->not->toBe($results['b']['pid'])
            ->and($states)->toBe(['applied', 'version_conflict'])
            ->and(DB::table('prc_network_budgets')->where('scope_key', $scope)->count())->toBe(1)
            ->and(DB::table('prc_source_targets')->where('platform_id', $platform->id)->count())->toBe(2)
            ->and(DB::table('prc_network_budgets')->where('scope_key', $scope)->sole()->policy_version)->toBe($version + 1);
    } finally {
        $targets = DB::table('prc_source_targets')->where('platform_id', $platform->id)->pluck('id');
        DB::table('prc_route_target_health')->whereIn('target_id', $targets)->delete();
        DB::table('prc_target_health')->whereIn('target_id', $targets)->delete();
        DB::table('prc_source_targets')->whereIn('id', $targets)->delete();
        Platform::query()->whereKey($platform->id)->delete();
    }
});
