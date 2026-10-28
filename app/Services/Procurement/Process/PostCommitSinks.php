<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProcessEventEnvelope;
use App\Services\Procurement\Process\Contracts\TransitionSink;
use App\Support\Telemetry\TelemetrySanitizer;
use Illuminate\Support\Facades\DB;

/**
 * Fanout optional technical sinks после фактического commit внешней транзакции (observability#s03).
 * ProcessTransaction внутри уже открытой tx только отпускает savepoint и не коммитит внешнюю tx; DB::afterCommit
 * держит публикацию до этого commit и отбрасывает её при rollback. На уровне 0 callback исполняется сразу.
 * Сбой построения envelope или любого sink остаётся внутри callback: не откатывает commit и не повторяет handler.
 */
final readonly class PostCommitSinks
{
    /** @var list<TransitionSink> */
    private array $sinks;

    /** @param list<TransitionSink>|null $sinks null — стандартный набор: structured log + Sentry breadcrumb */
    public function __construct(?array $sinks = null)
    {
        $sanitizer = new TelemetrySanitizer;
        $this->sinks = $sinks ?? [new LogTransitionSink($sanitizer), new SentryTransitionSink($sanitizer)];
    }

    /** @param \Closure(): ProcessEventEnvelope $envelope Строится лениво после commit: ошибка построения — сбой sink, не work. */
    public function afterCommit(\Closure $envelope): void
    {
        DB::afterCommit(function () use ($envelope): void {
            try {
                $event = $envelope();
            } catch (\Throwable $failure) {
                ProcessLog::sinkFailed('envelope', $failure);

                return;
            }
            foreach ($this->sinks as $sink) {
                try {
                    $sink->publish($event);
                } catch (\Throwable $failure) {
                    ProcessLog::sinkFailed($sink::class, $failure);
                }
            }
        });
    }
}
