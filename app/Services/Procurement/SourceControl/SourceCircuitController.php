<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Crawler\SourceCircuitState;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Platform\Registry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Персистентный брейкер источника (площадки) и единая точка ручного управления.
 *
 * Все переходы — под `SELECT … FOR UPDATE` строки площадки, поэтому параллельные прогоны
 * одной площадки видят согласованное состояние; логика переходов — `SourceCircuitPolicy`.
 * Автоматика (процессоры, `crawler:source-circuits`) и оператор (админка) зовут одни и те же
 * методы — «двойное управление» без расхождения правил.
 */
final readonly class SourceCircuitController
{
    public const string SystemActor = 'system';

    public function __construct(
        private SourceCircuitPolicy $policy,
        private SourceIncidentRecorder $incidents,
    ) {}

    public function platformIdFor(Crawler $crawler): int
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw new LogicException("Профиль #{$crawler->id} не связан с реестром: площадку брейкера не определить.");
        }

        return $registry->platform_id;
    }

    /** Строка брейкера площадки; создаётся с порогами из `crawler.source_circuit`. */
    public function circuitFor(int $platformId): SourceCircuit
    {
        SourceCircuit::query()->insertOrIgnore([
            'platform_id' => $platformId,
            'state' => SourceCircuitState::Closed->value,
            'failure_threshold' => $this->intConfig('crawler.source_circuit.failure_threshold', 5),
            'retry_interval_minutes' => $this->intConfig('crawler.source_circuit.retry_interval_minutes', 10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SourceCircuit::query()->where('platform_id', $platformId)->firstOrFail();
    }

    /**
     * Перед каждым блоком. Открытый брейкер с наступившим `retry_at` переводится в half-open:
     * этот блок — проба.
     */
    public function gate(Crawler $crawler, ?Run $run = null): SourceGateDecision
    {
        $platformId = $this->platformIdFor($crawler);

        return $this->locked($platformId, function (SourceCircuit $circuit) use ($crawler, $run, $platformId): SourceGateDecision {
            $decision = $this->policy->gate($circuit->toSnapshot(), CarbonImmutable::now());

            if ($decision->probe) {
                $circuit->fillFromSnapshot($this->policy->toHalfOpen($circuit->toSnapshot()));
                $circuit->changed_by = self::SystemActor;
                $circuit->save();

                $this->incidents->record(
                    platformId: $platformId,
                    type: SourceIncidentType::CircuitHalfOpened,
                    message: 'Пауза источника истекла: пробный блок.',
                    crawlerId: $crawler->id,
                    runId: $run?->id,
                    actor: self::SystemActor,
                );
            }

            return $decision;
        });
    }

    /**
     * Отказ блока. Возвращает состояние брейкера ПОСЛЕ учёта: `Open` — источник на паузе и
     * курсор двигать нельзя (системный сбой), иначе блок пропускается и записан инцидентом.
     *
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>|null  $requestParams
     */
    public function recordFailure(
        Crawler $crawler,
        ?Run $run,
        ListingFailureKind $kind,
        ?int $httpStatus = null,
        ?int $fetchId = null,
        ?array $cursor = null,
        ?array $requestParams = null,
        ?string $detail = null,
    ): SourceCircuitState {
        $platformId = $this->platformIdFor($crawler);

        return $this->locked($platformId, function (SourceCircuit $circuit) use (
            $crawler, $run, $kind, $httpStatus, $fetchId, $cursor, $requestParams, $detail, $platformId,
        ): SourceCircuitState {
            $before = $circuit->toSnapshot();
            $after = $this->policy->onFailure($before, $kind, CarbonImmutable::now());
            $opened = $before->state !== SourceCircuitState::Open && $after->state === SourceCircuitState::Open;

            $circuit->fillFromSnapshot($after);
            $circuit->changed_by = self::SystemActor;
            $circuit->save();

            $suffix = $detail === null ? '' : " {$detail}";

            if (! $opened) {
                $this->incidents->record(
                    platformId: $platformId,
                    type: $kind->skipsBlock() ? SourceIncidentType::BlockSkipped : SourceIncidentType::BlockDegraded,
                    message: ($kind->skipsBlock() ? 'Блок пропущен: ' : 'Блок прочитан частично: ').$kind->label().'.'.$suffix,
                    crawlerId: $crawler->id,
                    runId: $run?->id,
                    fetchId: $fetchId,
                    failureKind: $kind,
                    httpStatus: $httpStatus,
                    cursor: $cursor,
                    requestParams: $requestParams,
                    context: ['consecutive_failures' => $after->consecutiveFailures],
                    actor: self::SystemActor,
                );

                return $after->state;
            }

            $this->incidents->record(
                platformId: $platformId,
                type: SourceIncidentType::CircuitOpened,
                message: "Источник на паузе до {$after->retryAt?->toIso8601String()}: {$kind->label()}, отказов подряд {$after->consecutiveFailures}.{$suffix}",
                crawlerId: $crawler->id,
                runId: $run?->id,
                fetchId: $fetchId,
                failureKind: $kind,
                httpStatus: $httpStatus,
                cursor: $cursor,
                requestParams: $requestParams,
                context: [
                    'open_count' => $after->openCount,
                    'retry_at' => $after->retryAt?->toIso8601String(),
                    'previous_state' => $before->state->value,
                ],
                actor: self::SystemActor,
            );

            return $after->state;
        });
    }

    /** Успешный блок. Без записи, если брейкер и так закрыт со сброшенным счётчиком. */
    public function recordSuccess(Crawler $crawler, ?Run $run = null): void
    {
        $platformId = $this->platformIdFor($crawler);
        $current = $this->circuitFor($platformId);

        if ($current->state === SourceCircuitState::Closed && $current->consecutive_failures === 0) {
            return;
        }

        $this->locked($platformId, function (SourceCircuit $circuit) use ($crawler, $run, $platformId): void {
            $before = $circuit->toSnapshot();
            $after = $this->policy->onSuccess($before);

            if ($after == $before) {
                return;
            }

            $circuit->fillFromSnapshot($after);
            $circuit->changed_by = self::SystemActor;
            $circuit->save();

            if ($before->state === SourceCircuitState::HalfOpen) {
                $this->incidents->record(
                    platformId: $platformId,
                    type: SourceIncidentType::CircuitClosed,
                    message: 'Пробный блок успешен: источник снова работает.',
                    crawlerId: $crawler->id,
                    runId: $run?->id,
                    actor: self::SystemActor,
                );
            }
        });
    }

    /** Ручная пауза: запросы запрещены до ручного возобновления, таймер не действует. */
    public function pause(int $platformId, string $actor, ?string $reason = null): void
    {
        $this->locked($platformId, function (SourceCircuit $circuit) use ($platformId, $actor, $reason): void {
            $circuit->fillFromSnapshot($this->policy->pause($circuit->toSnapshot()));
            $circuit->changed_by = $actor;
            $circuit->save();

            $this->incidents->record(
                platformId: $platformId,
                type: SourceIncidentType::ManualPause,
                message: 'Источник поставлен на паузу оператором.'.($reason === null || $reason === '' ? '' : " {$reason}"),
                actor: $actor,
            );
        });
    }

    /**
     * Возобновление (оператор — «запустить сейчас», минуя ожидание; автоматика — не используется,
     * она идёт через half-open). Брейкер закрывается, счётчики сброшены.
     */
    public function resume(int $platformId, string $actor): void
    {
        $this->locked($platformId, function (SourceCircuit $circuit) use ($platformId, $actor): void {
            $circuit->fillFromSnapshot($this->policy->resume($circuit->toSnapshot()));
            $circuit->changed_by = $actor;
            $circuit->save();

            $this->incidents->record(
                platformId: $platformId,
                type: SourceIncidentType::ManualResume,
                message: 'Источник возобновлён оператором.',
                actor: $actor,
            );
        });
    }

    /**
     * @template T
     *
     * @param  Closure(SourceCircuit): T  $callback
     * @return T
     */
    private function locked(int $platformId, Closure $callback): mixed
    {
        $this->circuitFor($platformId);

        return DB::transaction(function () use ($platformId, $callback): mixed {
            $circuit = SourceCircuit::query()->where('platform_id', $platformId)->lockForUpdate()->firstOrFail();

            return $callback($circuit);
        });
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
