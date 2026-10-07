<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Хранение брейкеров этапов (`prc_source_circuits`). Только доступ к данным: правила
 * переходов — в `SourceCircuitBreaker`.
 */
final readonly class SourceCircuitRepository
{
    /** Строка (площадка, этап); создаётся при первом обращении с порогами из `crawler.source_circuit`. */
    public function forStage(int $platformId, SourceStage $stage): SourceCircuit
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

    /**
     * Изменение под `SELECT … FOR UPDATE`: параллельные воркеры видят согласованный счётчик.
     *
     * @template T
     *
     * @param  Closure(SourceCircuit): T  $callback
     * @return T
     */
    public function withLock(int $platformId, SourceStage $stage, Closure $callback): mixed
    {
        $this->forStage($platformId, $stage);

        return DB::transaction(fn (): mixed => $callback(
            SourceCircuit::query()->where('platform_id', $platformId)->where('stage', $stage)->lockForUpdate()->firstOrFail(),
        ));
    }

    public function resetFailures(int $platformId, SourceStage $stage): void
    {
        SourceCircuit::query()
            ->where('platform_id', $platformId)
            ->where('stage', $stage)
            ->where('consecutive_failures', '>', 0)
            ->update(['consecutive_failures' => 0, 'updated_at' => now()]);
    }

    /** @return Collection<int, SourceCircuit> этапы, у которых истекло ожидание по таймеру */
    public function waitingExpired(Carbon $now): Collection
    {
        return SourceCircuit::query()
            ->whereNotNull('retry_at')
            ->where('retry_at', '<=', $now)
            ->where('manually_paused', false)
            ->get();
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
