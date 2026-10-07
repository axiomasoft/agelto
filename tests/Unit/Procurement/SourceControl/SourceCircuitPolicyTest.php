<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use App\Services\Procurement\SourceControl\SourceCircuitPolicy;
use App\Services\Procurement\SourceControl\SourceCircuitSnapshot;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->policy = new SourceCircuitPolicy;
    $this->now = CarbonImmutable::parse('2026-10-07 12:00:00');
});

test('closed: порог подряд идущих отказов открывает брейкер на retry_interval', function (): void {
    $circuit = new SourceCircuitSnapshot(failureThreshold: 3, retryIntervalMinutes: 10);

    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::SourceUnavailable, $this->now);
    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::SourceBlocked, $this->now);

    expect($circuit->state)->toBe(SourceCircuitState::Closed)->and($circuit->consecutiveFailures)->toBe(2);

    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::UnrecognizedFormat, $this->now);

    expect($circuit->state)->toBe(SourceCircuitState::Open)
        ->and($circuit->openCount)->toBe(1)
        ->and($circuit->retryAt?->toDateTimeString())->toBe('2026-10-07 12:10:00');
});

test('closed: успех сбрасывает счётчик, ошибки строк не копятся', function (): void {
    $circuit = new SourceCircuitSnapshot(failureThreshold: 2);
    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::SourceBlocked, $this->now);
    $circuit = $this->policy->onSuccess($circuit);
    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::RowErrors, $this->now);
    $circuit = $this->policy->onFailure($circuit, ListingFailureKind::SourceBlocked, $this->now);

    expect($circuit->state)->toBe(SourceCircuitState::Closed)->and($circuit->consecutiveFailures)->toBe(1);
});

test('исчерпанный пул прокси открывает брейкер сразу', function (): void {
    $circuit = $this->policy->onFailure(new SourceCircuitSnapshot(failureThreshold: 50), ListingFailureKind::ProxyPoolExhausted, $this->now);

    expect($circuit->state)->toBe(SourceCircuitState::Open);
});

test('open: гейт отказывает до retry_at и пропускает пробу после', function (): void {
    $circuit = new SourceCircuitSnapshot(state: SourceCircuitState::Open, retryAt: $this->now->addMinutes(10));

    $denied = $this->policy->gate($circuit, $this->now->addMinutes(9));
    $probe = $this->policy->gate($circuit, $this->now->addMinutes(10));

    expect($denied->allowed)->toBeFalse()
        ->and($denied->retryAt?->toDateTimeString())->toBe('2026-10-07 12:10:00')
        ->and($probe->allowed)->toBeTrue()
        ->and($probe->probe)->toBeTrue();
});

test('half-open: успех закрывает, отказ снова открывает с новым сроком', function (): void {
    $halfOpen = $this->policy->toHalfOpen(new SourceCircuitSnapshot(state: SourceCircuitState::Open, openCount: 1, retryIntervalMinutes: 15));

    $closed = $this->policy->onSuccess($halfOpen);
    $reopened = $this->policy->onFailure($halfOpen, ListingFailureKind::SourceUnavailable, $this->now);

    expect($halfOpen->state)->toBe(SourceCircuitState::HalfOpen)
        ->and($closed->state)->toBe(SourceCircuitState::Closed)
        ->and($closed->retryAt)->toBeNull()
        ->and($reopened->state)->toBe(SourceCircuitState::Open)
        ->and($reopened->openCount)->toBe(2)
        ->and($reopened->retryAt?->toDateTimeString())->toBe('2026-10-07 12:15:00');
});

test('open: запоздалый успех не закрывает брейкер', function (): void {
    $open = new SourceCircuitSnapshot(state: SourceCircuitState::Open, retryAt: $this->now);

    expect($this->policy->onSuccess($open)->state)->toBe(SourceCircuitState::Open);
});

test('ручная пауза запрещает запросы до ручного возобновления, возобновление закрывает брейкер', function (): void {
    $paused = $this->policy->pause(new SourceCircuitSnapshot);

    expect($this->policy->gate($paused, $this->now)->allowed)->toBeFalse()
        ->and($this->policy->gate($paused, $this->now)->retryAt)->toBeNull();

    $resumed = $this->policy->resume($this->policy->onFailure($paused, ListingFailureKind::ProxyPoolExhausted, $this->now));

    expect($resumed->state)->toBe(SourceCircuitState::Closed)
        ->and($resumed->manuallyPaused)->toBeFalse()
        ->and($this->policy->gate($resumed, $this->now)->allowed)->toBeTrue();
});

test('выключенный брейкер не открывается, но ручная пауза действует', function (): void {
    $disabled = new SourceCircuitSnapshot(enabled: false, failureThreshold: 1);
    $after = $this->policy->onFailure($disabled, ListingFailureKind::SourceBlocked, $this->now);

    expect($after->state)->toBe(SourceCircuitState::Closed)
        ->and($this->policy->gate($after, $this->now)->allowed)->toBeTrue()
        ->and($this->policy->gate($this->policy->pause($after), $this->now)->allowed)->toBeFalse();
});
