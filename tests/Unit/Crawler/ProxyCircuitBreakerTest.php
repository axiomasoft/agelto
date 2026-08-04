<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Services\Procurement\Proxy\ProxyCircuitBreaker;

beforeEach(function (): void {
    config([
        'crawler.proxy.breaker.threshold.global' => 5,
        'crawler.proxy.breaker.threshold.target' => 3,
        'crawler.proxy.breaker.base_cooldown_minutes.global' => 30,
        'crawler.proxy.breaker.base_cooldown_minutes.target' => 15,
        'crawler.proxy.breaker.cap_hours' => 6,
        'crawler.proxy.breaker.http_429.threshold' => 10,
        'crawler.proxy.breaker.http_429.base_cooldown_minutes' => 2,
    ]);
});

test('сетевые и креды-причины классифицируются как глобальный уровень', function (): void {
    $breaker = new ProxyCircuitBreaker;

    expect($breaker->level(ProxyFailureReason::NetworkTimeout))->toBe(ProxyCircuitBreaker::LevelGlobal)
        ->and($breaker->level(ProxyFailureReason::TlsError))->toBe(ProxyCircuitBreaker::LevelGlobal)
        ->and($breaker->level(ProxyFailureReason::ConnectFailed))->toBe(ProxyCircuitBreaker::LevelGlobal)
        ->and($breaker->level(ProxyFailureReason::ProxyAuthFailed))->toBe(ProxyCircuitBreaker::LevelGlobal);
});

test('ответы цели классифицируются как целевой уровень', function (): void {
    $breaker = new ProxyCircuitBreaker;

    expect($breaker->level(ProxyFailureReason::Http403))->toBe(ProxyCircuitBreaker::LevelTarget)
        ->and($breaker->level(ProxyFailureReason::Http429))->toBe(ProxyCircuitBreaker::LevelTarget)
        ->and($breaker->level(ProxyFailureReason::EmptyBody))->toBe(ProxyCircuitBreaker::LevelTarget)
        ->and($breaker->level(ProxyFailureReason::Unknown))->toBe(ProxyCircuitBreaker::LevelTarget);
});

test('серия отказов ниже порога не открывает breaker', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $state = $breaker->onFailure(consecutiveFailures: 1, openCount: 0, reason: ProxyFailureReason::NetworkTimeout);

    expect($state['consecutive_failures'])->toBe(2)
        ->and($state['open_count'])->toBe(0)
        ->and($state['open_until'])->toBeNull();
});

test('достижение порога открывает breaker и обнуляет серию', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $state = $breaker->onFailure(consecutiveFailures: 4, openCount: 0, reason: ProxyFailureReason::NetworkTimeout);

    expect($state['consecutive_failures'])->toBe(0)
        ->and($state['open_count'])->toBe(1)
        ->and($state['open_until'])->not->toBeNull()
        ->and((int) round($state['open_until']->diffInMinutes(now(), absolute: true)))->toBe(30);
});

test('повторное открытие удваивает cooldown экспоненциально', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $state = $breaker->onFailure(consecutiveFailures: 4, openCount: 1, reason: ProxyFailureReason::NetworkTimeout);

    expect($state['open_count'])->toBe(2)
        ->and((int) round($state['open_until']->diffInMinutes(now(), absolute: true)))->toBe(60);
});

test('cooldown не превышает cap', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $state = $breaker->onFailure(consecutiveFailures: 4, openCount: 10, reason: ProxyFailureReason::NetworkTimeout);

    expect((int) round($state['open_until']->diffInMinutes(now(), absolute: true)))->toBe(6 * 60);
});

test('http_429 использует отдельный порог и короткий cooldown целевого уровня', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $belowThreshold = $breaker->onFailure(consecutiveFailures: 8, openCount: 0, reason: ProxyFailureReason::Http429);
    expect($belowThreshold['open_until'])->toBeNull();

    $atThreshold = $breaker->onFailure(consecutiveFailures: 9, openCount: 0, reason: ProxyFailureReason::Http429);
    expect($atThreshold['open_until'])->not->toBeNull()
        ->and((int) round($atThreshold['open_until']->diffInMinutes(now(), absolute: true)))->toBe(2);
});

test('http403 (не 429) открывается по обычному целевому порогу', function (): void {
    $breaker = new ProxyCircuitBreaker;

    $state = $breaker->onFailure(consecutiveFailures: 2, openCount: 0, reason: ProxyFailureReason::Http403);

    expect($state['open_count'])->toBe(1)
        ->and((int) round($state['open_until']->diffInMinutes(now(), absolute: true)))->toBe(15);
});

test('успех сбрасывает серию, счётчик открытий и breaker', function (): void {
    $breaker = new ProxyCircuitBreaker;

    expect($breaker->onSuccess())->toBe([
        'consecutive_failures' => 0,
        'open_count' => 0,
        'open_until' => null,
    ]);
});
