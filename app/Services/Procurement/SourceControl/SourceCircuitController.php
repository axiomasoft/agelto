<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Platform\Registry;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Брейкер этапа источника (площадка × этап) — единственное место, где меняется его состояние;
 * автоматика и админка зовут одни и те же методы.
 *
 * Правило целиком: отказ, который считается (`countsTowardCircuit`), увеличивает счётчик
 * подряд идущих отказов; успех сбрасывает. На `failure_threshold` этап уходит в ожидание на
 * `retry_interval_minutes` (`retry_at`), счётчик обнуляется. После ожидания этап просто
 * работает снова. Отказы, пришедшие во время ожидания (запрос был в полёте), не считаются.
 *
 * Ревьюеру: намеренно нет half-open и «немедленного открытия» по виду отказа — цена этого
 * упрощения не больше `failure_threshold` лишних запросов после ожидания.
 *
 * Вызывать вне транзакции блока: состояние и инциденты должны сохраниться, даже если блок
 * откатится (см. `SourceRunControl`).
 */
final readonly class SourceCircuitController
{
    public const string SystemActor = 'system';

    public function __construct(private SourceIncidentRecorder $incidents) {}

    public function platformIdFor(Crawler $crawler): int
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw new LogicException("Профиль #{$crawler->id} не связан с реестром: площадку брейкера не определить.");
        }

        return $registry->platform_id;
    }

    /** Строка брейкера; создаётся при первом обращении с порогами из `crawler.source_circuit`. */
    public function circuitFor(int $platformId, SourceStage $stage): SourceCircuit
    {
        SourceCircuit::query()->insertOrIgnore([
            'platform_id' => $platformId,
            'stage' => $stage->value,
            'failure_threshold' => $this->intConfig('crawler.source_circuit.failure_threshold', 5),
            'retry_interval_minutes' => $this->intConfig('crawler.source_circuit.retry_interval_minutes', 10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SourceCircuit::query()->where('platform_id', $platformId)->where('stage', $stage)->firstOrFail();
    }

    public function admits(int $platformId, SourceStage $stage): bool
    {
        return $this->circuitFor($platformId, $stage)->admits();
    }

    /**
     * Учесть отказ. Возвращает `true`, если этап теперь не принимает запросы (ушёл в ожидание
     * или стоит на ручной паузе) — вызывающий останавливается и не двигает курсор.
     *
     * @param  array<string, mixed>  $context  попадёт в инцидент об уходе в ожидание
     */
    public function recordFailure(int $platformId, SourceStage $stage, SourceFailureKind $kind, array $context = []): bool
    {
        return $this->locked($platformId, $stage, function (SourceCircuit $circuit) use ($platformId, $stage, $kind, $context): bool {
            $circuit->last_failure_kind = $kind;
            $circuit->last_failure_at = now();

            if ($kind->countsTowardCircuit() && ! $circuit->isWaiting()) {
                $circuit->consecutive_failures++;
            }

            $failures = $circuit->consecutive_failures;
            $opens = $circuit->enabled && ! $circuit->isWaiting() && $failures >= max(1, $circuit->failure_threshold);
            $retryAt = now()->addMinutes(max(1, $circuit->retry_interval_minutes));

            if ($opens) {
                $circuit->retry_at = $retryAt;
                $circuit->consecutive_failures = 0;
                $circuit->changed_by = self::SystemActor;
            }

            $circuit->save();

            if ($opens) {
                $this->incidents->record(
                    platformId: $platformId,
                    type: SourceIncidentType::CircuitOpened,
                    message: "{$stage->getLabel()}: {$kind->label()}, отказов подряд {$failures}. Ожидание до {$retryAt->toIso8601String()}.",
                    failureKind: $kind,
                    context: ['stage' => $stage->value] + $context,
                    actor: self::SystemActor,
                );
            }

            return ! $circuit->admits();
        });
    }

    public function recordSuccess(int $platformId, SourceStage $stage): void
    {
        SourceCircuit::query()
            ->where('platform_id', $platformId)
            ->where('stage', $stage)
            ->where('consecutive_failures', '>', 0)
            ->update(['consecutive_failures' => 0, 'updated_at' => now()]);
    }

    /** Ручная пауза: запросов нет до ручного возобновления, таймер не действует. */
    public function pause(SourceCircuit $circuit, string $actor, ?string $reason = null): void
    {
        $circuit->update(['manually_paused' => true, 'changed_by' => $actor]);

        $this->incidents->record(
            platformId: $circuit->platform_id,
            type: SourceIncidentType::ManualPause,
            message: "{$circuit->stage->getLabel()}: пауза оператором.".($reason === null || $reason === '' ? '' : " {$reason}"),
            context: ['stage' => $circuit->stage->value],
            actor: $actor,
        );
    }

    /** «Возобновить сейчас»: снимает паузу и ожидание, счётчик обнуляется. */
    public function resume(SourceCircuit $circuit, string $actor): void
    {
        $circuit->update(['manually_paused' => false, 'retry_at' => null, 'consecutive_failures' => 0, 'changed_by' => $actor]);

        $this->incidents->record(
            platformId: $circuit->platform_id,
            type: SourceIncidentType::ManualResume,
            message: "{$circuit->stage->getLabel()}: возобновлено оператором.",
            context: ['stage' => $circuit->stage->value],
            actor: $actor,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(SourceCircuit): T  $callback
     * @return T
     */
    private function locked(int $platformId, SourceStage $stage, Closure $callback): mixed
    {
        $this->circuitFor($platformId, $stage);

        return DB::transaction(fn (): mixed => $callback(
            SourceCircuit::query()->where('platform_id', $platformId)->where('stage', $stage)->lockForUpdate()->firstOrFail(),
        ));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
