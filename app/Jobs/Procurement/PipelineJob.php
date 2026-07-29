<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Шаблонный метод для jobs пайплайна документов (P4.2+/P5/P6): status-guard первым
 * выражением `handle()`, lease на время обработки (`App\Models\Concerns\HasLease`),
 * единый error-блок при провале (`App\Models\Concerns\HasErrorBlock` — backoff-формула
 * живёт ТОЛЬКО там, этот абстракт её не дублирует), release ВСЕГДА в `finally`. Исключение из
 * `process()` не глотается: `recordFailure()` фиксирует попытку, затем исключение пробрасывается
 * дальше (Laravel retry/backoff механика job'а срабатывает как обычно).
 *
 * Уникальность диспатча — выбор КОНКРЕТНОГО job'а (D39), не этого абстракта: короткие
 * single-shot jobs (prepare/screening) — `ShouldBeUnique` (lock до конца обработки);
 * checkpoint-requeue job (extraction, P6.1) — `ShouldBeUniqueUntilProcessing` (lock снимается
 * в начале, self-dispatch продолжения внутри `handle()` успешно перекладывает job). Оба варианта
 * используют один и тот же `uniqueId()`, собранный из `pipelineUniqueId()`.
 */
abstract class PipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    final public function handle(): void
    {
        if (! $this->statusGuardPassed()) {
            return;
        }

        if (! $this->acquireLease()) {
            return;
        }

        try {
            $this->process();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            throw $exception;
        } finally {
            $this->releaseLease();
        }

        $this->afterRelease();
    }

    /**
     * Первое выражение обработки: false — документ/анализ уже прошёл эту стадию (или
     * в несовместимом статусе) — job молча завершается, дублей не создаёт.
     */
    abstract protected function statusGuardPassed(): bool;

    /**
     * Атомарный захват лиза (`HasLease::acquireLease()`) на время обработки; false —
     * стадию уже кто-то обрабатывает (двойной диспатч — no-op).
     */
    abstract protected function acquireLease(): bool;

    /**
     * Освобождение лиза (`HasLease::releaseLease()`) — вызывается безусловно из `finally`.
     */
    abstract protected function releaseLease(): void;

    /**
     * Фиксация провала стадии через `HasErrorBlock::recordFailure()` целевой модели.
     * Реализация НЕ глотает исключение — только пишет error-блок, `handle()` пробрасывает
     * его дальше сама.
     */
    abstract protected function recordFailure(Throwable $exception): void;

    /**
     * Полезная нагрузка стадии (P4.2+): выполняется только после успешного status-guard
     * и захвата лиза.
     */
    abstract protected function process(): void;

    /**
     * Ключ уникальности стадии — `{ownerId}:{stage}`; конкретный job использует его
     * в собственном `uniqueId()` при реализации `ShouldBeUnique`/`ShouldBeUniqueUntilProcessing`.
     */
    abstract public function pipelineUniqueId(): string;

    /**
     * TTL unique-лока в секундах (`Illuminate\Bus\UniqueLock::acquire()` проверяет
     * `method_exists($job, 'uniqueFor')` раньше свойства `$uniqueFor`). Без объявления `$uniqueFor`
     * пропадает в `0`: на Redis (прод) `SETNX` без `EXPIRE` — лок вечен, убитый воркер (SIGKILL/OOM/
     * рестарт) навсегда запирает документ (ADR-0009). Общий для всех pipeline-job'ов — единственный
     * owner перехода статуса снимает лок штатно, TTL страхует только случай убитого воркера.
     */
    public function uniqueFor(): int
    {
        return (int) config('ai.pipeline.unique_lock_minutes', 60) * 60;
    }

    /**
     * Хук ПОСЛЕ успешного `releaseLease()` (не вызывается, если `process()` бросил исключение —
     * тогда `handle()` пробрасывает его дальше сразу за `finally`). No-op по умолчанию.
     * `EstimateExtractionJob` (P6.1, D39) переопределяет для self-dispatch продолжения
     * checkpoint-requeue СТРОГО после снятия лиза (release-then-dispatch) — диспатч ДО release
     * создавал гонку: конкурентный воркер, забравший продолжение раньше текущего запуска,
     * проваливал `acquireLease()` (лиз ещё держит текущий запуск) и становился no-op без
     * повторного диспатча, молча теряя прогресс.
     */
    protected function afterRelease(): void {}
}
