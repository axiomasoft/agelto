<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use Carbon\CarbonImmutable;

/**
 * Чистая логика брейкера источника `closed → open → half-open → closed` без персистентности:
 * `SourceCircuitController` читает строку `prc_source_circuits` под блокировкой, применяет
 * переход и сохраняет результат.
 *
 * - `Closed`: каждый учитываемый отказ блока увеличивает счётчик подряд идущих отказов;
 *   успех его сбрасывает. Достижение порога (или отказ, открывающий сразу, — пул прокси
 *   исчерпан) открывает брейкер до `now + retry_interval`.
 * - `Open`: запросы к источнику запрещены до `retry_at`; после него первый вызов `gate()`
 *   переводит в `HalfOpen` и пропускает пробный блок.
 * - `HalfOpen`: успех закрывает брейкер, учитываемый отказ снова открывает его.
 * - Ручная пауза оператора запрещает запросы независимо от состояния и таймера.
 */
final readonly class SourceCircuitPolicy
{
    public function gate(SourceCircuitSnapshot $circuit, CarbonImmutable $now): SourceGateDecision
    {
        if ($circuit->manuallyPaused) {
            return SourceGateDecision::deny(retryAt: null);
        }

        if (! $circuit->enabled) {
            return SourceGateDecision::allow();
        }

        return match ($circuit->state) {
            SourceCircuitState::Closed, SourceCircuitState::HalfOpen => SourceGateDecision::allow(),
            SourceCircuitState::Open => $circuit->retryAt === null || $now->greaterThanOrEqualTo($circuit->retryAt)
                ? SourceGateDecision::probe()
                : SourceGateDecision::deny(retryAt: $circuit->retryAt),
        };
    }

    public function onFailure(SourceCircuitSnapshot $circuit, ListingFailureKind $kind, CarbonImmutable $now): SourceCircuitSnapshot
    {
        if (! $circuit->enabled || ! $kind->countsTowardCircuit() || $circuit->state === SourceCircuitState::Open) {
            return $circuit->with(lastFailureKind: $kind, lastFailureAt: $now);
        }

        $consecutive = $circuit->consecutiveFailures + 1;
        $mustOpen = $circuit->state === SourceCircuitState::HalfOpen
            || $kind->opensCircuitImmediately()
            || $consecutive >= max(1, $circuit->failureThreshold);

        if (! $mustOpen) {
            return $circuit->with(consecutiveFailures: $consecutive, lastFailureKind: $kind, lastFailureAt: $now);
        }

        return $circuit->with(
            state: SourceCircuitState::Open,
            consecutiveFailures: $consecutive,
            openCount: $circuit->openCount + 1,
            openedAt: $now,
            retryAt: $now->addMinutes(max(1, $circuit->retryIntervalMinutes)),
            lastFailureKind: $kind,
            lastFailureAt: $now,
        );
    }

    public function onSuccess(SourceCircuitSnapshot $circuit): SourceCircuitSnapshot
    {
        return match ($circuit->state) {
            // Ответ, пришедший уже после открытия, не закрывает брейкер: решение принимает
            // пробный блок после паузы или оператор.
            SourceCircuitState::Open => $circuit,
            SourceCircuitState::Closed, SourceCircuitState::HalfOpen => $circuit->with(
                state: SourceCircuitState::Closed,
                consecutiveFailures: 0,
                openCount: 0,
                clearRetryAt: true,
            ),
        };
    }

    public function toHalfOpen(SourceCircuitSnapshot $circuit): SourceCircuitSnapshot
    {
        return $circuit->with(state: SourceCircuitState::HalfOpen);
    }

    /** Ручное возобновление: брейкер закрыт, счётчики сброшены, пауза снята. */
    public function resume(SourceCircuitSnapshot $circuit): SourceCircuitSnapshot
    {
        return $circuit->with(
            state: SourceCircuitState::Closed,
            consecutiveFailures: 0,
            openCount: 0,
            manuallyPaused: false,
            clearRetryAt: true,
        );
    }

    public function pause(SourceCircuitSnapshot $circuit): SourceCircuitSnapshot
    {
        return $circuit->with(manuallyPaused: true);
    }
}
