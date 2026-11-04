<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Services\Procurement\Process\ReconcileDaemon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('reconcile daemon expires abandoned outbound owner and reserved permit without a business retry', function (): void {
    $f = new F;
    $context = $f->context();
    $ticket = $f->ticket($context);
    $permit = $f->repo->reserve($ticket, $context);
    expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class);
    DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
        ->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

    $daemon = app(ReconcileDaemon::class);
    expect($daemon->categories())->toContain('outbound_expired_owners');
    $daemon->tick();
    expect(DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->value('state'))->toBe('expired')
        ->and(DB::table('prc_network_permits')->where('id', $permit->id->value)->value('state'))->toBe('expired')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
    $daemon->tick();
    expect(DB::table('prc_outbound_operations')->count())->toBe(1)
        ->and(DB::table('prc_network_demands')->count())->toBe(1);
})->group('network-demand-recovery');
