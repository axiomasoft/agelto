<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpResponseHead;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportMetrics;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\QuotaReceiptBoundary;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\TestFilesystemIsolation;

beforeEach(function (): void {
    F::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
});
afterEach(function (): void {
    $this->wire->stop();
    F::clean();
});
pest()->group('t35', 't23', 'p23');

test('reserve затем pause revoke retire epoch policy или 429 даёт zero server receipts', function (string $change): void {
    $f = $this->f;
    $context = $f->context();
    $permit = $f->reserve($context);
    match ($change) {
        'pause' => DB::table('prc_control_scopes')->where('scope_key', 'system')->update(['desired_mode' => 'paused']),
        'expired' => DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]),
        'generation' => DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->increment('generation'),
        'retire' => DB::table('prc_source_targets')->where('id', $f->target->value)->update(['retired_at' => DB::raw('clock_timestamp()')]),
        'epoch' => DB::table('prc_route_target_health')->increment('health_epoch'),
        'policy' => DB::table('prc_network_budgets')->increment('policy_version'),
        '429' => $f->repo->cooldown($permit->ticket->plan, new RetryAt(new DateTimeImmutable('+60 seconds'))),
    };
    expect($f->send($permit, $context, $this->wire))->toBeFalse()
        ->and($this->wire->receipts())->toHaveCount(0)->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_network_permits')->where('id', $permit->id->value)->sole()->state)->toBe('released');
})->with(['pause', 'expired', 'generation', 'retire', 'epoch', 'policy', '429']);

test('real Process prefix повторно проверяет handler hold lease input и membership', function (string $change): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $context = new ExecutionContext($lease, app(WorkStoreRepository::class), app(ProcessClock::class), Duration::seconds(240), Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
    $f = $this->f;
    $permit = $f->reserve($context);
    match ($change) {
        'hold' => ExecutionFixtures::holdHandler(StageCode::Listing),
        'lease' => FinalizeFixtures::expire($work),
        'input' => DB::table('prc_crawl_blocks')->where('id', DB::table('prc_process_work')->where('id', $work->value->value)->sole()->crawl_block_id)->increment('head_generation'),
        'membership' => DB::table('prc_process_reservations')->where('work_id', $work->value->value)->delete(),
    };
    expect($f->send($permit, $context, $this->wire))->toBeFalse()->and($this->wire->receipts())->toHaveCount(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->with(['hold', 'lease', 'input', 'membership']);

test('duplicate claimSend и повтор capability не создают второй physical send', function (): void {
    $f = $this->f;
    $context = $f->context();
    $permit = $f->reserve($context);
    expect($f->send($permit, $context, $this->wire))->toBeTrue()->and($f->send($permit, $context, $this->wire))->toBeFalse()
        ->and($this->wire->receipts())->toHaveCount(1)->and(DB::table('prc_request_attempts')->count())->toBe(1);
    $again = $f->repo->claimSend($permit, $context);
    expect($again)->toBeInstanceOf(NetworkSendClaim::class)->and($again->claimed)->toBeFalse();
});

test('late slot более 30s продлевает full timeout cleanup и удерживает inflight после прежней expiry', function (): void {
    $f = $this->f;
    $other = $f->context();
    $checked = false;
    $oldExpiry = 0;
    $context = $f->context(function () use (&$checked, &$oldExpiry, $f, $other): void {
        if (! $checked && $oldExpiry > 0 && microtime(true) > $oldExpiry) {
            $checked = true;
            $ticket = $f->ticket($other);
            expect($f->repo->reserve($ticket, $other))->toBeInstanceOf(NetworkPermitWait::class);
        }
    });
    $permit = $f->reserve($context, $f->plan(url: $this->wire->url('/headers-late')));
    // DB fixture reproduces a scheduler delay of 33s without a wall-clock sleep in the test.
    DB::table('prc_network_permits')->where('id', $permit->id->value)->update(['slot_at' => DB::raw("clock_timestamp() - interval '33 seconds'"), 'expires_at' => DB::raw("clock_timestamp() + interval '1 second'")]);
    $oldExpiry = microtime(true) + 1;
    expect($f->send($permit, $context, $this->wire))->toBeTrue()->and($checked)->toBeTrue()->and($this->wire->receipts())->toHaveCount(1);
    $row = DB::selectOne('SELECT extract(epoch FROM expires_at - authorized_at) AS coverage FROM prc_network_permits WHERE id = ?', [$permit->id->value]);
    expect((float) $row->coverage)->toBeGreaterThanOrEqual(34.0);
});

test('late authorizations проходят send_tat и retired expired foreign permits не оживают', function (): void {
    $f = $this->f;
    DB::table('prc_network_budgets')->update(['max_inflight' => 2]);
    $a = $f->context();
    $b = $f->context();
    $pa = $f->reserve($a);
    $pb = $f->reserve($b);
    DB::table('prc_network_permits')->update(['slot_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
    $first = $f->repo->claimSend($pa, $a);
    $second = $f->repo->claimSend($pb, $b);
    expect($first)->toBeInstanceOf(NetworkSendClaim::class)->and($second)->toBeInstanceOf(NetworkPermitWait::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1);
    DB::table('prc_network_permits')->where('id', $pb->id->value)->update(['expires_at' => DB::raw("clock_timestamp() - interval '1 microsecond'")]);
    expect($f->repo->claimSend($pb, $b))->toBeInstanceOf(TransportBlocked::class);
    expect($f->repo->claimSend($pa, $b))->toBeInstanceOf(TransportBlocked::class);
});

test('executor использует реальный scheduled gate для retries и redirect без hidden sends', function (): void {
    $f = $this->f;
    $outcome = app(OutboundExecutor::class)->execute($f->plan(url: $this->wire->url('/sequence')), $f->context(), $f->gate(), $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
    expect($outcome)->toBeInstanceOf(TransportSucceeded::class)->and($this->wire->receipts())->toHaveCount(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(3)->and(DB::table('prc_network_permits')->where('authorized_at', '!=', null)->count())->toBe(3);
});

test('полный request timeout finalization не помещается и не расходует ticket TAT или transport', function (): void {
    $f = $this->f;
    $context = $f->context();
    DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update([
        'lease_until' => DB::raw("clock_timestamp() + interval '10 seconds'"), 'deadline_at' => DB::raw("clock_timestamp() + interval '10 seconds'")]);
    $before = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    $result = $f->repo->register($f->plan(), $context, $f->route);
    expect($result)->toBeInstanceOf(TransportDeferred::class)
        ->and($result->reason)->toBe(NetworkDeferralReason::SliceExhausted)
        ->and(DB::table('prc_network_demands')->count())->toBe(0)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($before)
        ->and($this->wire->receipts())->toHaveCount(0);
});

test('expiry после locked reads но до final authorization CAS не возрождает permit и не тратит send_tat', function (): void {
    $f = $this->f;
    $context = $f->context();
    $permit = $f->reserve($context);
    $armed = true;
    // Query boundary hook only in the test: real persisted expiry advances after the row snapshot.
    DB::listen(function (QueryExecuted $query) use (&$armed, $permit): void {
        if ($armed && str_contains($query->sql, 'SELECT 1 AS live FROM prc_outbound_operations')) {
            $armed = false;
            DB::table('prc_network_permits')->where('id', $permit->id->value)->update(['expires_at' => DB::raw("clock_timestamp() - interval '1 microsecond'")]);
        }
    });
    $before = DB::table('prc_network_budgets')->orderBy('id')->pluck('send_tat')->all();
    expect($f->send($permit, $context, $this->wire))->toBeFalse()->and($armed)->toBeFalse()
        ->and($this->wire->receipts())->toHaveCount(0)->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('send_tat')->all())->toBe($before);
});

test('stored demand deadline не продлевается новым context или более длинной owner lease', function (): void {
    $f = $this->f;
    $context = $f->context();
    $permit = $f->reserve($context);
    DB::table('prc_network_demands')->where('id', $permit->ticket->id->value)->update(['operation_deadline' => DB::raw("clock_timestamp() + interval '10 seconds'")]);
    expect($f->send($permit, $context, $this->wire))->toBeFalse()
        ->and($this->wire->receipts())->toHaveCount(0)->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('P25-01 SIGKILL at receipt commit boundary preserves atomic quota release and replay', function (string $boundary): void {
    $f = $this->f;
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 1, 'max_inflight' => 2]);
    $other = $f->context();
    $reserved = $f->reserve($other);
    $context = $f->context();
    $permit = $f->reserve($context, $f->plan(url: $this->wire->url('/quota')));
    $marker = sys_get_temp_dir().'/quota-boundary-'.bin2hex(random_bytes(6));
    $token = 'runtime-p24-quota-'.bin2hex(random_bytes(6));
    $root = TestFilesystemIsolation::prepare($token);
    $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";'
        .'$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .'\Tests\Support\TestFilesystemIsolation::configure($app);'
        .'\Tests\Support\ProcessCore\Http\QuotaReceiptBoundary::run(';
    $args = [$permit->ticket->id->value, $permit->ticket->plan->url->reveal(), $marker, $boundary];
    $code .= implode(',', array_map(fn (string $arg): string => var_export($arg, true), $args)).');';
    $worker = new Process(['php', '-r', $code], base_path(), [
        'PROCESS_CORE_PROCESS' => $token, 'TMPDIR' => $root.'/tmp', 'TMP' => $root.'/tmp', 'TEMP' => $root.'/tmp',
    ]);
    $worker->setTimeout(15)->start();
    try {
        $until = hrtime(true) + 8_000_000_000;
        while (! is_file($marker) && $worker->isRunning() && hrtime(true) < $until) {
            usleep(10_000);
        }
        expect(is_file($marker))->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        $point = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
        expect($point['transaction_level'])->toBe($boundary === 'before_commit' ? 1 : 0)
            ->and($point['backend_pid'])->not->toBe(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
        if ($boundary === 'after_commit') {
            // The exact old record-return/before-cooldown seam: the other reserved send is already fenced.
            expect($f->send($reserved, $other, $this->wire))->toBeFalse()
                ->and(DB::table('prc_request_attempts')->sole()->status)->toBe(429)
                ->and(DB::table('prc_network_permits')->where('id', $permit->id->value)->sole()->state)->toBe('released');
        } else {
            expect(DB::table('prc_request_attempts')->sole()->finished_at)->toBeNull()
                ->and(DB::table('prc_observations')->count())->toBe(0)
                ->and(DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->not_before)->toBe('-infinity');
        }
        $worker->signal(9);
        $worker->wait();
        expect($worker->hasBeenSignaled())->toBeTrue()->and($worker->getTermSignal())->toBe(9);
        if ($boundary === 'before_commit') {
            expect(DB::table('prc_request_attempts')->sole()->finished_at)->toBeNull()
                ->and(DB::table('prc_network_permits')->where('id', $permit->id->value)->sole()->state)->toBe('sent')
                ->and(DB::table('prc_observations')->count())->toBe(0)
                ->and(DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->not_before)->toBe('-infinity');
        }
        $result = unserialize(file_get_contents($marker.'.result'), ['allowed_classes' => [
            HttpAttemptResult::class, HttpResponseHead::class, TransportMetrics::class,
            ResponseEnvelope::class, DecodedTextBody::class,
        ]]);
        expect($result)->toBeInstanceOf(HttpAttemptResult::class);
        $decision = $f->repo->record($permit->ticket->plan->physicalRequestId, $result);
        $before = DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole();
        $duplicate = $f->repo->record($permit->ticket->plan->physicalRequestId, $result);
        expect($decision)->toBeInstanceOf(TransportDeferred::class)
            ->and($duplicate->retryAt->value)->toEqual($decision->retryAt->value)
            ->and(DB::table('prc_network_budgets')->where('id', $before->id)->sole()->not_before)->toBe($before->not_before)
            ->and(new DateTimeImmutable($before->not_before))->toEqual($decision->retryAt->value)
            ->and(new DateTimeImmutable($before->tat))->toBeGreaterThanOrEqual($decision->retryAt->value)
            ->and(DB::table('prc_observations')->count())->toBe(1)
            ->and(DB::table('prc_observation_applications')->count())->toBe(3);
        if ($boundary === 'before_commit') {
            expect($f->send($reserved, $other, $this->wire))->toBeFalse();
        }
        $next = app(OutboundExecutor::class)->execute($f->plan(), $f->context(), $f->gate(), $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($next)->toBeInstanceOf(TransportDeferred::class)->and($next->reason)->toBe(NetworkDeferralReason::RateLimit)
            ->and($next->retryAt->value)->toBeGreaterThanOrEqual($decision->retryAt->value)
            ->and(array_column($this->wire->receipts(), 'path'))->toBe(['/quota'])
            ->and(DB::table('prc_request_attempts')->count())->toBe(1);
        QuotaReceiptBoundary::evidence($boundary, $this->wire, $decision, ['signal' => 9, ...$point]);
    } finally {
        $worker->stop(0.1, 9);
        foreach ([$marker, $marker.'.result'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        TestFilesystemIsolation::cleanup($token);
    }
})->with(['before_commit', 'after_commit']);

test('P25-02 malformed 429 commits both quota and body failure and fences reserved and fresh sends', function (string $path, string $failure): void {
    $f = $this->f;
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 1, 'max_inflight' => 2]);
    $other = $f->context();
    $reserved = $f->reserve($other);
    $outcome = app(OutboundExecutor::class)->execute($f->plan(url: $this->wire->url($path)), $f->context(), $f->gate(),
        $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
    expect($outcome)->toBeInstanceOf(TransportDeferred::class)->and($outcome->reason)->toBe(NetworkDeferralReason::RateLimit);
    $request = DB::table('prc_request_attempts')->sole();
    $details = json_decode(DB::table('prc_observations')->sole()->details, true, flags: JSON_THROW_ON_ERROR);
    $budget = DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole();
    expect($request->status)->toBe(429)->and($request->outcome)->toBe($failure)->and($request->finished_at)->not->toBeNull()
        ->and($details['http_status'])->toBe(429)->and($details['retry_after'])->toBe('120')
        ->and($details['quota']['decision'])->toBe('rate_limit')
        ->and(new DateTimeImmutable($details['quota']['retry_at']))->toEqual($outcome->retryAt->value)
        ->and(new DateTimeImmutable($budget->not_before))->toEqual($outcome->retryAt->value)
        ->and($outcome->retryAt->value->getTimestamp() - (new DateTimeImmutable($request->finished_at))->getTimestamp())->toBe(120)
        ->and(DB::table('prc_network_permits')->where('demand_id', $request->demand_id)->sole()->state)->toBe('released');
    foreach (['prc_route_health', 'prc_route_target_health'] as $table) {
        expect(DB::table($table)->sole()->consecutive_failures)->toBe(0)->and(DB::table($table)->sole()->state)->toBe('closed');
    }
    expect($f->send($reserved, $other, $this->wire))->toBeFalse();
    $next = app(OutboundExecutor::class)->execute($f->plan(), $f->context(), $f->gate(), $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
    expect($next)->toBeInstanceOf(TransportDeferred::class)->and($next->reason)->toBe(NetworkDeferralReason::RateLimit)
        ->and($next->retryAt->value)->toBeGreaterThanOrEqual($outcome->retryAt->value)
        ->and(array_column($this->wire->receipts(), 'path'))->toBe([$path])
        ->and(DB::table('prc_request_attempts')->count())->toBe(1);
    QuotaReceiptBoundary::evidence(str_replace('-', '_', ltrim($path, '/')), $this->wire, $outcome);
})->with([
    'invalid UTF8' => ['/quota-invalid-utf8', 'decode'],
    'invalid gzip' => ['/quota-badgzip', 'decode'],
    'unsupported encoding' => ['/quota-unsupported', 'decode'],
    'gzip expansion' => ['/quota-biggzip', 'byte_limit'],
    'entity overflow' => ['/quota-large', 'byte_limit'],
]);

test('P25-01 receipt keeps the authorized origin when catalog metadata changes after send', function (): void {
    $f = $this->f;
    $armed = true;
    DB::listen(function (QueryExecuted $query) use ($f, &$armed): void {
        if ($armed && str_contains($query->sql, 'SELECT a.route_id, a.target_id, d.origin_budget_id')) {
            $armed = false;
            DB::table('prc_source_targets')->where('id', $f->target->value)->update(['canonical_origin' => 'https://moved.test']);
        }
    });
    $outcome = app(OutboundExecutor::class)->execute($f->plan(url: $this->wire->url('/quota')), $f->context(), $f->gate(),
        $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
    expect($armed)->toBeFalse()->and($outcome)->toBeInstanceOf(TransportDeferred::class)
        ->and($outcome->reason)->toBe(NetworkDeferralReason::RateLimit)
        ->and(DB::table('prc_request_attempts')->sole()->status)->toBe(429)
        ->and(new DateTimeImmutable(DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->not_before))->toEqual($outcome->retryAt->value)
        ->and(array_column($this->wire->receipts(), 'path'))->toBe(['/quota']);
    QuotaReceiptBoundary::evidence('pinned_origin', $this->wire, $outcome);
});
