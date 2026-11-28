<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Extraction\ExtractionProgress;
use App\Dto\Procurement\Ai\Screening\ScreeningProgress;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Enums\Llm\ProviderSendDenial;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Procurement\Extraction\Provider\AnalysisSendPolicy;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\ProcessCore\ProviderSendFixtures;

pest()->group('analysis-execution', 'provider-send-fencing');

test('полный timeout не помещается: slice denial без network demand или paid send', function (): void {
    $server = new WireFixture;
    try {
        $f = new ProviderSendFixtures($server);
        $context = new ExecutionContext($f->lease, app(LeaseKeeper::class), app(ProcessClock::class),
            Duration::seconds(1), Duration::seconds(390), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
        $gate = ProviderSendFixtures::gateFor($f->lease, $f->run, $f->target, $f->route, $f->profile, $server, $context);
        $wire = app(StructuredCallExecutor::class)->compileGeneration($f->plan, $server->url('/')->origin->value());
        expect($wire->fingerprint)->toBe($f->wire->fingerprint)
            ->and($gate->claimSend($f->operation, $wire)->denial)->toBe(ProviderSendDenial::SliceExhausted)
            ->and($server->receipts())->toBe([])
            ->and(PackageNetworkFacts::demands()->count())->toBe(0)
            ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
            ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->outcome)->toBe('not_sent')
            ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000');
    } finally {
        $server->stop();
    }
});

test('потерянный lease сохраняет управляющий выход вместо financial denial', function (): void {
    $server = new WireFixture;
    try {
        $f = new ProviderSendFixtures($server);
        $keeper = Mockery::mock(LeaseKeeper::class);
        $keeper->shouldReceive('heartbeat')->once()->andThrow(LeaseLost::forWork($f->work->value->value));
        $clock = Mockery::mock(ProcessClock::class);
        $clock->shouldReceive('monotonicNs')->andReturn(0, 20_000_000_000);
        $clock->shouldReceive('dbNow')->andReturn(new DbInstant($f->lease->leaseUntil->modify('-390 seconds')));
        $context = new ExecutionContext($f->lease, $keeper, $clock, Duration::seconds(300), Duration::seconds(390),
            Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
        $gate = ProviderSendFixtures::gateFor($f->lease, $f->run, $f->target, $f->route, $f->profile, $server, $context);
        expect(fn () => $gate->claimSend($f->operation, $f->wire))->toThrow(LeaseLost::class)
            ->and($server->receipts())->toBe([])
            ->and(PackageNetworkFacts::demands()->count())->toBe(0)
            ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
            ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000');
    } finally {
        $server->stop();
    }
});

test('отказы gate сохраняют объявленную причину и точный retry instant', function (ProviderSendDenial $denial, string $reason): void {
    $retry = new DateTimeImmutable('+123 seconds');
    $denied = new AnalysisSendDenied(new SendClaimResult(null, $denial, $retry));
    foreach ([new ExtractionProgress, new ScreeningProgress] as $progress) {
        $outcome = app(AnalysisSendPolicy::class)->outcome($denied, $progress);
        expect($outcome)->toBeInstanceOf(StageDeferred::class)
            ->and($outcome->reason->value)->toBe($reason)
            ->and($outcome->retryAt->value)->toEqual($retry);
    }
})->with([[ProviderSendDenial::Network, 'no_route'], [ProviderSendDenial::Financial, 'provider_budget'],
    [ProviderSendDenial::RateLimit, 'rate_limit'], [ProviderSendDenial::CapacityWait, 'capacity_wait'],
    [ProviderSendDenial::TargetCooldown, 'target_cooldown'], [ProviderSendDenial::Dependency, 'provider_unavailable']]);

test('slice возврат сохраняет тот же stage progress вместо сброса checkpoint', function (): void {
    foreach ([new ExtractionProgress, new ScreeningProgress(reaskOrdinal: 1)] as $progress) {
        $outcome = app(AnalysisSendPolicy::class)->outcome(new AnalysisSendDenied(new SendClaimResult(null, ProviderSendDenial::SliceExhausted)), $progress);
        expect($outcome)->toBeInstanceOf(StagePartial::class)->and($outcome->checkpoint)->toBe($progress);
    }
});

test('ручные и configuration отказы не маскируются как автоматическое ожидание budget', function (ProviderSendDenial $denial, string $reason): void {
    $outcome = app(AnalysisSendPolicy::class)->outcome(new AnalysisSendDenied(new SendClaimResult(null, $denial)), new ExtractionProgress);
    expect($outcome)->toBeInstanceOf(StageBlocked::class)->and($outcome->reason->value)->toBe($reason);
})->with([[ProviderSendDenial::PolicyReview, 'policy_review'], [ProviderSendDenial::AlreadyClaimed, 'policy_review'],
    [ProviderSendDenial::Paused, 'paused'], [ProviderSendDenial::Configuration, 'invalid_recipe_configuration'],
    [ProviderSendDenial::Unsupported, 'invalid_recipe_configuration'], [ProviderSendDenial::WireMismatch, 'invalid_recipe_configuration']]);

test('revoked authority остаётся управляющим выходом runner', function (): void {
    expect(fn () => app(AnalysisSendPolicy::class)->outcome(new AnalysisSendDenied(new SendClaimResult(null, ProviderSendDenial::Revoked)), new ExtractionProgress))
        ->toThrow(LeaseLost::class);
});
