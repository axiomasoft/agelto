<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Network\BoundedDnsResolver;
use App\Services\Procurement\Network\HttpTransport;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\RetryPolicy;
use App\Services\Procurement\Network\UrlPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\Http\FixtureAttemptGate;
use Tests\Support\ProcessCore\Http\TransportFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

test('T21 every private reserved mapped и multicast literal denied before connect', function (): void {
    foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.168.1.2', '224.0.0.1', '[::1]', '[::ffff:127.0.0.1]', '[fe80::1]', '[ff02::1]'] as $host) {
        $url = new SafeUrl('http://'.$host.'/');
        expect(fn () => (new UrlPolicy([$url->origin]))->resolve($url))->toThrow(InvalidArgumentException::class);
    }
    $public = new SafeUrl('https://8.8.8.8/');
    expect((new UrlPolicy([$public->origin]))->resolve($public)->address)->toBe('8.8.8.8');
    expect(fn () => new SafeUrl('https://user:secret@example.test/'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new HttpRequestPayload(['Host' => 'other.test']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new HttpRequestPayload(['Authorization' => "Bearer abc\r\nHost: other.test"]))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new UrlPolicy([CanonicalOrigin::fromUrl('https://example.test')]))->resolve(new SafeUrl('https://example.test:8443/')))->toThrow(InvalidArgumentException::class);
})->group('t21');

test('T04 RetryAfter seconds date и invalid fallback bounded без сокращения', function (): void {
    $policy = new RetryPolicy;
    $now = new DateTimeImmutable('2026-10-28 20:00:00 UTC');
    expect($policy->retryAfter('120', $now)->value->getTimestamp())->toBe($now->getTimestamp() + 120)
        ->and($policy->retryAfter('Wed, 28 Oct 2026 20:03:00 GMT', $now)->value->getTimestamp())->toBe($now->getTimestamp() + 180)
        ->and($policy->retryAfter('86400', $now)->value->getTimestamp())->toBe($now->getTimestamp() + 86400)
        ->and($policy->retryAfter('86401', $now))->toBeNull()
        ->and($policy->retryAfter('9999999999999999999999999', $now))->toBeNull()
        ->and($policy->retryAfter('Thu, 29 Oct 2026 20:00:01 GMT', $now))->toBeNull();
    foreach (['-1', 'nonsense', null, 'Wed, 32 Oct 2026 20:03:00 GMT'] as $invalid) {
        expect($policy->retryAfter($invalid, $now)->value->getTimestamp())->toBe($now->getTimestamp() + 60);
    }
    expect(fn () => new RetryPolicy(-1))->toThrow(InvalidArgumentException::class);
})->group('t04');

test('T25 low speed и DB loss являются typed outcomes с закрытым sink', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        $gate = F::gate();
        $budget = new TransferBudget(1_000_000, 1_000_000);
        $profile = F::profile(['attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => []]);
        $start = hrtime(true);
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/body-stall'), $profile), F::context(), $gate, $wire->policy(), $budget);
        expect($result->reason)->toBe(NetworkDeferralReason::AttemptsExhausted)
            ->and($gate->results[0]->failure)->toBe(TransportFailure::Timeout)
            ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(4.5);
        $started = hrtime(true);
        $context = F::context(checkpoint: function () use ($started): void {
            if (hrtime(true) - $started > 300_000_000) {
                throw new DependencyUnavailable('fixture_db');
            }
        });
        $gate = F::gate();
        $spools = glob(sys_get_temp_dir().'/php*');
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/stall')), $context, $gate, $wire->policy(), $budget);
        expect($result->reason)->toBe(NetworkDeferralReason::DependencyUnavailable)
            ->and($gate->results)->toHaveCount(1)->and(glob(sys_get_temp_dir().'/php*'))->toBe($spools);
    } finally {
        $wire->stop();
    }
})->group('t25');

test('T21 canonical wire host closes DNS binding bypass а HTTPS downgrade rejected', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        config(['outbound.http.tls_bundles' => ['fixture.test' => $wire->root.'/cert.pem']]);
        $url = new SafeUrl('HTTP://FIXTURE.TEST.:'.$wire->ports['http'].'/ok');
        $result = app(OutboundExecutor::class)->execute(F::plan($url), F::context(), F::gate(), $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($wire->receipts()[0]['headers']['Host'])->toBe('fixture.test:'.$wire->ports['http']);
        $gate = F::gate();
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/redirect-downgrade', true)), F::context(), $gate, $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result->reason)->toBe(NetworkBlockedReason::UrlPolicyDenied)->and($gate->requests)->toHaveCount(1);
    } finally {
        $wire->stop();
    }
})->group('t21');

test('T47 storage failure defers без transport retry и partial artifacts', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        $disk = Storage::fake('local');
        $disk->put('outbound', 'a file blocks the staged directory');
        $gate = F::gate();
        $profile = F::profile(['response_kind' => 'binary', 'max_text_bytes' => null, 'charset_default' => null]);
        $spools = glob(sys_get_temp_dir().'/php*');
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/ok'), $profile), F::context(), $gate, $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result->reason)->toBe(NetworkDeferralReason::DependencyUnavailable)->and($gate->requests)->toHaveCount(1)
            ->and($gate->results[0]->failure)->toBe(TransportFailure::Storage)
            ->and(glob(sys_get_temp_dir().'/php*'))->toBe($spools);
    } finally {
        $wire->stop();
    }
})->group('t47');

pest()->group('p21', 'p22');

test('T04 actual CONNECT timeout на A затем B success ровно два requests', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        $a = new HttpRouteBinding(LedgerFixtures::uuid(), 'http://127.0.0.1:'.$wire->ports['stall_proxy']);
        $b = new HttpRouteBinding(LedgerFixtures::uuid());
        $gate = new FixtureAttemptGate([$a, $b]);
        $profile = F::profile(['low_speed_seconds' => 1, 'total_seconds' => 2]);
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/ok'), $profile), F::context(), $gate, $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($gate->requests)->toHaveCount(2)->and($gate->results[0]->failure)->toBe(TransportFailure::Timeout)
            ->and($gate->requests[1]->route->id->value)->toBe($b->id->value)
            ->and(array_filter($wire->receipts(), fn ($r) => $r['kind'] === 'http'))->toHaveCount(1);
    } finally {
        $wire->stop();
    }
})->group('t04');

test('T03 pause после первого response запрещает повторный send и binary redirect очищает промежуточный artifact', function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        $disk = Storage::fake('local');
        $gate = F::gate();
        $gate->afterRecord = function ($gate): void {
            $gate->deny = new TransportBlocked(NetworkBlockedReason::Paused);
        };
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/unavailable')), F::context(), $gate, $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result->reason)->toBe(NetworkBlockedReason::Paused)->and($wire->receipts())->toHaveCount(1);
        $gate = F::gate();
        $profile = F::profile(['response_kind' => 'binary', 'max_text_bytes' => null, 'charset_default' => null]);
        $result = app(OutboundExecutor::class)->execute(F::plan($wire->url('/redirect'), $profile), F::context(), $gate, $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($disk->allFiles('outbound'))->toHaveCount(1)->and($gate->requests)->toHaveCount(2);
        $wireCount = count($wire->receipts());
        $plan = F::plan($wire->url('/ok'));
        $capability = new AuthorizedHttpRequest($plan, new HttpRouteBinding(LedgerFixtures::uuid()));
        expect(fn () => serialize($capability))->toThrow(LogicException::class);
        DB::beginTransaction();
        try {
            expect(fn () => app(HttpTransport::class)->sendOnce($capability, $wire->policy()->resolve($plan->url), new HttpRequestPayload, F::context(), new TransferBudget(100, 100)))->toThrow(LogicException::class);
        } finally {
            DB::rollBack();
        }
        expect($wire->receipts())->toHaveCount($wireCount);
    } finally {
        $wire->stop();
    }
})->group('t03', 't21');

test('T21 bounded resolver использует real isolated Docker DNS и запрещает private answer', function (): void {
    $context = F::context();
    $start = hrtime(true);
    $addresses = (new BoundedDnsResolver)->resolve('core-postgres', 2, $context);
    expect($addresses)->not->toBeEmpty()
        ->and(filter_var($addresses[0], FILTER_VALIDATE_IP))->not->toBeFalse()
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(3);
    $url = new SafeUrl('http://core-postgres:5432/');
    expect(fn () => (new UrlPolicy([$url->origin]))->resolve($url, $context, 2))->toThrow(InvalidArgumentException::class);
})->group('t21');

test('P25-02 HTTP head survives malformed quota entity independently of body outcome', function (string $path, TransportFailure $failure): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $wire = new WireFixture;
    try {
        $gate = F::gate();
        $outcome = app(OutboundExecutor::class)->execute(F::plan($wire->url($path)), F::context(), $gate,
            $wire->policy(), new TransferBudget(1_000_000, 1_000_000));
        expect($outcome)->toBeInstanceOf(TransportDeferred::class)
            ->and($outcome->reason)->toBe(NetworkDeferralReason::RateLimit)
            ->and($gate->results)->toHaveCount(1)
            ->and($gate->results[0]->failure)->toBe($failure)
            ->and($gate->results[0]->response)->toBeNull()
            ->and($gate->results[0]->head->status)->toBe(429)
            ->and($gate->results[0]->head->header('Retry-After'))->toBe('120')
            ->and($wire->receipts())->toHaveCount(1);
    } finally {
        $wire->stop();
    }
})->with([
    'invalid UTF8' => ['/quota-invalid-utf8', TransportFailure::Decode],
    'invalid gzip' => ['/quota-badgzip', TransportFailure::Decode],
    'unsupported encoding' => ['/quota-unsupported', TransportFailure::Decode],
    'decompressed entity limit' => ['/quota-biggzip', TransportFailure::ByteLimit],
    'plain entity limit' => ['/quota-large', TransportFailure::ByteLimit],
])->group('t04', 't47');
