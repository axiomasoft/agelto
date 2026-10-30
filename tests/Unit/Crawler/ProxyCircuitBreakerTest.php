<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HealthMutation;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpResponseHead;
use App\Enums\Procurement\Network\CircuitState as C;
use App\Enums\Procurement\Network\HealthScope as Scope;
use App\Enums\Procurement\Network\HealthSignal as S;
use App\Enums\Procurement\Network\RouteKind;
use App\Enums\Procurement\Network\TransportFailure as F;
use App\Services\Procurement\Network\HealthReducer;
use App\Services\Procurement\Network\RequestClassifier;

// Historical scenario identities retained for the immutable regression inventory. P2.6 replaces
// the old proxy breaker with phase attribution and the canonical reducer. TLS, 403 and 429 never
// punish route health; credential failures hold until operator action; success preserves debt.
function oldBreakerSnapshot(int $consecutive = 0, int $openings = 0): HealthSnapshot
{
    return new HealthSnapshot(Scope::Route, 'fixture', C::Closed, 1, $consecutive, $openings, null, null, null, null, null);
}

function canonicalBreakerFailure(int $consecutive, int $openings = 0): HealthMutation
{
    return app(HealthReducer::class)->reduce(oldBreakerSnapshot($consecutive, $openings), S::Failure, 'proxy_connect',
        new DateTimeImmutable('2026-10-30T00:00:00Z'), 1, 1, 0, false);
}

test('сетевые и креды-причины классифицируются как глобальный уровень', function (): void {
    $classifier = new RequestClassifier;
    expect($classifier->classify(new HttpAttemptResult(null, F::ProxyConnect, 0, 0), RouteKind::Proxy)->route)->toBe(S::Failure)
        ->and($classifier->classify(new HttpAttemptResult(null, F::ProxyAuth, 0, 0), RouteKind::Proxy)->route)->toBe(S::CredentialHold)
        ->and($classifier->classify(new HttpAttemptResult(null, F::Tls, 0, 0), RouteKind::Proxy)->route)->toBe(S::Ignored);
});

test('ответы цели классифицируются как целевой уровень', function (): void {
    $classified = (new RequestClassifier)->classify(new HttpAttemptResult(null, F::Timeout, 0, 0), RouteKind::Proxy);
    expect($classified->route)->toBe(S::Ignored)->and($classified->routeTarget)->toBe(S::Failure)->and($classified->target)->toBe(S::Failure);
});

test('серия отказов ниже порога не открывает breaker', function (): void {
    $state = canonicalBreakerFailure(1);
    expect($state->consecutive)->toBe(2)->and($state->openings)->toBe(0)->and($state->openUntil)->toBeNull()->and($state->state)->toBe(C::Closed);
});

test('достижение порога открывает breaker и обнуляет серию', function (): void {
    $state = canonicalBreakerFailure(4);
    expect($state->consecutive)->toBe(0)->and($state->openings)->toBe(1)->and($state->state)->toBe(C::Open)
        ->and($state->openUntil->getTimestamp() - strtotime('2026-10-30T00:00:00Z'))->toBe(1800);
});

test('повторное открытие удваивает cooldown экспоненциально', function (): void {
    $state = canonicalBreakerFailure(4, 1);
    expect($state->openings)->toBe(2)->and($state->openUntil->getTimestamp() - strtotime('2026-10-30T00:00:00Z'))->toBe(3600);
});

test('cooldown не превышает cap', function (): void {
    $state = canonicalBreakerFailure(4, 10);
    expect($state->openUntil->getTimestamp() - strtotime('2026-10-30T00:00:00Z'))->toBe(21600);
});

test('http_429 использует отдельный порог и короткий cooldown целевого уровня', function (): void {
    // Accepted replacement: rate throttling is independent of route health, even at its threshold.
    $classified = (new RequestClassifier)->classify(new HttpAttemptResult(null, F::Truncated, 0, 0, head: new HttpResponseHead(429, [])), RouteKind::Proxy);
    expect($classified->route)->toBe(S::Ignored)->and($classified->routeTarget)->toBe(S::Ignored)->and($classified->target)->toBe(S::Ignored);
    expect(app(HealthReducer::class)->reduce(oldBreakerSnapshot(4, 2), $classified->route, 'http_429', new DateTimeImmutable, 10, 10, 0, false))->toBeNull();
});

test('http403 (не 429) открывается по обычному целевому порогу', function (): void {
    // Accepted replacement: a proven origin response is positive transport evidence.
    $classified = (new RequestClassifier)->classify(new HttpAttemptResult(null, F::Decode, 1, 1, head: new HttpResponseHead(403, [])), RouteKind::Proxy);
    expect($classified->route)->toBe(S::Ignored)->and($classified->routeTarget)->toBe(S::Ignored);
});

test('успех сбрасывает серию, счётчик открытий и breaker', function (): void {
    $state = app(HealthReducer::class)->reduce(oldBreakerSnapshot(4, 2), S::Success, 'http_200', new DateTimeImmutable, 1, 0, 0, false);
    expect($state->state)->toBe(C::Closed)->and($state->consecutive)->toBe(0)->and($state->openUntil)->toBeNull()
        ->and($state->openings)->toBe(2)->and($state->stableSince)->not->toBeNull();
});
