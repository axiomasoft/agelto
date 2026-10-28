<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\LegacyBridge;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

beforeEach(fn () => NetworkFixtures::platform());

test('bounded bridge закрывается finally и сохраняет immutable control closure', function (): void {
    NetworkFixtures::open();
    $bridge = app(LegacyBridge::class);
    $owner = null;
    expect(function () use ($bridge, &$owner): void {
        $bridge->run('fixture.call', null, ['acquisition:platform:fixture'], 100, LedgerFixtures::hash('policy'), function (OutboundExecutionContext $context) use (&$owner): never {
            $handle = $context->lease();
            $owner = $handle;
            expect(DB::table('prc_outbound_operation_controls')->where('operation_id', $handle->id->value)->count())->toBe(3);
            throw new RuntimeException('caller failed');
        });
    })->toThrow(RuntimeException::class, 'caller failed');
    expect($owner)->toBeInstanceOf(OutboundOperationHandle::class);
    assert($owner instanceof OutboundOperationHandle);
    expect(DB::table('prc_outbound_operations')->where('id', $owner->id->value)->sole()->state)->toBe('closed');
    expect($bridge->renew($owner))->toBeNull();
});

test('expiry отменяет pending и reserved но сохраняет sent facts и rate debt', function (): void {
    $owner = NetworkFixtures::open();
    $first = NetworkFixtures::demand($owner, ['state' => 'granted']);
    $reserved = NetworkFixtures::permit($first);
    $second = NetworkFixtures::demand($owner);
    $sent = NetworkFixtures::permit($second);
    DB::table('prc_network_permits')->where('id', $sent)->update(['state' => 'sent', 'authorized_at' => DB::raw('clock_timestamp()')]);
    DB::table('prc_network_budgets')->update(['tat' => '2030-01-01T00:00:00Z']);
    $debt = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    DB::table('prc_outbound_operations')->where('id', $owner->id->value)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
    expect(app(OutboundOperationRepository::class)->renew($owner, 60))->toBeNull();
    expect(app(LegacyBridge::class)->expireStale())->toBe(1)
        ->and(app(LegacyBridge::class)->expireStale())->toBe(0)
        ->and(DB::table('prc_network_permits')->where('id', $reserved)->sole()->state)->toBe('expired')
        ->and(DB::table('prc_network_permits')->where('id', $sent)->sole()->state)->toBe('sent')
        ->and(DB::table('prc_network_demands')->where('id', $second)->sole()->state)->toBe('expired')
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($debt);
});

test('DDL отвергает half owner, неверные FK и duplicate pending operation', function (): void {
    $owner = NetworkFixtures::open();
    foreach ([['operation_generation' => null], ['operation_id' => null], ['work_generation' => 1], ['operation_generation' => 0]] as $bad) {
        expect(LedgerFixtures::sqlState(fn () => NetworkFixtures::demand($owner, $bad)))->toBe('23514');
    }
    expect(LedgerFixtures::sqlState(fn () => NetworkFixtures::demand($owner, ['route_id' => LedgerFixtures::uuid()->value])))->toBe('23503');
    $demand = NetworkFixtures::demand($owner);
    expect(LedgerFixtures::sqlState(fn () => NetworkFixtures::demand($owner)))->toBe('23505');
    $permit = NetworkFixtures::permit($demand);
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_network_permits')->where('id', $permit)->update(['state' => 'sent'])))->toBe('23514');
    $budget = DB::table('prc_network_budgets')->orderBy('id')->firstOrFail()->id;
    DB::table('prc_network_permit_scopes')->insert(['permit_id' => $permit, 'budget_id' => $budget]);
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_network_budgets')->where('id', $budget)->delete()))->toBe('23503');
    DB::table('prc_network_permits')->where('id', $permit)->delete();
    expect(DB::table('prc_network_permit_scopes')->count())->toBe(0);
});

test('request evidence для bridge хранится через demand без фиктивного ProcessAttempt', function (): void {
    $owner = NetworkFixtures::open();
    $demand = NetworkFixtures::demand($owner);
    $row = ['request_id' => LedgerFixtures::uuid()->value, 'demand_id' => $demand, 'request_seq' => 1,
        'started_at' => DB::raw('clock_timestamp()'), 'phase' => 'authorized', 'epochs' => '{}', 'epochs_schema_version' => 1];
    DB::table('prc_request_attempts')->insert($row);
    expect(DB::table('prc_request_attempts')->sole()->attempt_id)->toBeNull();
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_request_attempts')->insert([...$row, 'request_id' => LedgerFixtures::uuid()->value, 'demand_id' => null])))->toBe('23514');
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_request_attempts')->insert([...$row, 'request_id' => LedgerFixtures::uuid()->value, 'demand_id' => LedgerFixtures::uuid()->value])))->toBe('23503');
});

pest()->group('t37', 'p21');
