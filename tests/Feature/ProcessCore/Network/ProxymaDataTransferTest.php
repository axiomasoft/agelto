<?php

declare(strict_types=1);

use App\Services\Procurement\Proxyma\Migration\HistoricalRequests;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\ProcessCore\ProxymaUpgradeFixtures as U;

test('пустой перенос повторяем и dry-run не создаёт mapping или journal', function (): void {
    U::package();
    [$inventory, $catalog, $state, $id] = U::services();
    $before = DB::table('prc_network_cutover_journals')->count();
    $report = $inventory->dryRun();
    expect($report['tables']['prc_network_routes']['count'])->toBe(0)
        ->and($report['unmapped_ids']['proxy'])->toBe([])
        ->and(DB::table('prc_network_cutover_journals')->count())->toBe($before);
    $catalog->run($id, []);
    $state->run($id);
    $catalog->run($id, []);
    $state->run($id);
    expect(DB::table('prc_network_authorities')->sole()->authority)->toBe('legacy')
        ->and(DB::table('prc_network_cutover_journals')->sole()->phase)->toBe('draining');
});

test('явная карта обязательна orphan сообщается а недренированные permits блокируют финальную фазу', function (): void {
    U::package();
    $f = U::populate();
    [$inventory, $catalog, $state, $id] = U::services();
    expect(fn () => $catalog->run($id, []))->toThrow(DomainException::class)
        ->and(DB::table(PackageSchema::tables()['routes'])->count())->toBe(0);
    $orphan = Uuid::random()->value;
    DB::table('prc_network_routes')->insert(['id' => $orphan, 'route_key' => 'orphan', 'kind' => 'proxy', 'egress_id' => 'nat:orphan', 'enabled' => false, 'credential_version' => 1]);
    expect($inventory->dryRun()['orphans']['prc_network_routes'])->toBe([$orphan])
        ->and(fn () => $catalog->run($id, $f['map']))->toThrow(DomainException::class);
    DB::table('prc_network_routes')->where('id', $orphan)->delete();
    $catalog->run($id, $f['map']);
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['state' => 'sent']);
    expect($inventory->dryRun()['undrained']['permits'])->toBe([$f['permit']])
        ->and(fn () => $state->run($id))->toThrow(DomainException::class)
        ->and(DB::table(PackageSchema::tables()['budgets'])->count())->toBe(0);
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['state' => 'released']);
    $state->run($id);
    expect((new HistoricalRequests(DB::connection()))->page()[0]->request_id)->toBe($f['request']);
    expect(fn () => (new HistoricalRequests(DB::connection()))->page(limit: 101))->toThrow(InvalidArgumentException::class);
});

test('частичный повтор отвергает новую source revision и новую target revision без перезаписи долгов', function (): void {
    U::package();
    $f = U::populate();
    [$inventory, $catalog, $state, $id] = U::services();
    $catalog->run($id, $f['map']);
    $catalog->run($id, $f['map']);
    $route = $f['f']->route->routeId->value;
    DB::table('prc_network_routes')->where('id', $route)->increment('credential_version');
    expect(fn () => $state->run($id))->toThrow(DomainException::class)
        ->and(DB::table(PackageSchema::tables()['budgets'])->count())->toBe(0);
    DB::table('prc_network_routes')->where('id', $route)->decrement('credential_version');
    $state->run($id);
    $budget = DB::table(PackageSchema::tables()['budgets'])->first();
    DB::table(PackageSchema::tables()['budgets'])->where('id', $budget->id)->update(['tat' => '2027-01-01', 'revision' => $budget->revision + 1]);
    expect(fn () => $state->run($id))->toThrow(DomainException::class)
        ->and(DB::table(PackageSchema::tables()['budgets'])->where('id', $budget->id)->value('revision'))->toBe($budget->revision + 1);
    expect(fn () => DB::transaction(fn () => DB::table('prc_proxyma_transfer_records')->update(['checksum' => str_repeat('0', 64)])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('prc_proxyma_endpoint_links')->insert(['proxy_id' => array_key_first($f['map']), 'tenant_key' => 'system', 'endpoint_id' => $f['map'][array_key_first($f['map'])]['endpoint_id']])))->toThrow(QueryException::class);
});

test('terminal vocabulary определяется durable send facts а не словом released или expired', function (string $legacy, bool $sent, string $expected, bool $authorized): void {
    U::package();
    $f = U::populate();
    [$inventory, $catalog, $state, $id] = U::services();
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['state' => $legacy, 'authorized_at' => $authorized ? '2026-01-01' : null]);
    if (! $sent) {
        DB::table('prc_request_attempts')->where('request_id', $f['request'])->update(['sent_at' => null]);
    }
    $catalog->run($id, $f['map']);
    $state->run($id);
    expect(DB::table('prc_proxyma_state_archive')->where('source_table', 'prc_network_permits')->sole()->mapped_state)->toBe($expected);
})->with([
    ['released', true, 'completed', false], ['expired', true, 'completed', false],
    ['released', false, 'cancelled_before_send', false], ['expired', false, 'expired_before_send', false],
    ['released', false, 'unknown_after_authorization', true],
]);

test('изменение schema constraints после catalog блокирует state transfer', function (): void {
    U::package();
    [$inventory, $catalog, $state, $id] = U::services();
    $catalog->run($id, []);
    DB::statement('ALTER TABLE prc_proxyma_state_archive ADD CONSTRAINT fixture_forward_guard CHECK (length(source_key)>0)');
    expect(fn () => $state->run($id))->toThrow(DomainException::class);
});

test('status capacity сохраняет прежний spend без умножения на число proxy а превышение блокирует перенос', function (int $percent, bool $allowed): void {
    U::package();
    $f = U::populate();
    config(['outbound.scheduling.status_capacity_percent' => $percent]);
    DB::table('prc_network_demands')->where('physical_request_id', $f['request'])->update(['traffic_class' => 'status_refresh']);
    DB::table('prc_network_permits')->where('id', $f['permit'])->update(['authorized_at' => DB::raw("clock_timestamp()-interval '10 seconds'")]);
    [$inventory, $catalog, $state, $id] = U::services();
    $catalog->run($id, $f['map']);
    $tables = PackageSchema::tables();
    if (! $allowed) {
        expect(fn () => $state->run($id))->toThrow(DomainException::class)
            ->and(DB::table($tables['budgets'])->count())->toBe(0);

        return;
    }
    $state->run($id);
    $state->run($id);
    $class = DB::table($tables['budgets'])->where('traffic_class', 'status_refresh')->sole();
    expect($class->hour_limit)->toBe(10_800)
        ->and(DB::table($tables['quota_windows'])->where('budget_id', $class->id)->sole()->spent)->toBe(1)
        ->and(DB::table($tables['budgets'])->where('id', $class->id)->whereRaw("cooldown_until>=clock_timestamp()+interval '59 minutes'")->exists())->toBeTrue();
})->with([[30, true], [0, false]]);
