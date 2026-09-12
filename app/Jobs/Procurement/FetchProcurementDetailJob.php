<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\PipelineDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Один проход детальной карточки и немедленная передача новых документов в pipeline.
 *
 * `ShouldBeUnique`, а не `ShouldBeUniqueUntilProcessing`: job не диспатчит своё продолжение,
 * а уникальный lock должен защищать одну закупку до конца выполнения. Ретраи остаются в
 * доменном `detail_*`-контуре; свойства очередного ретрая здесь намеренно не объявляются.
 */
final class FetchProcurementDetailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $procurementId,
    ) {
        $this->onQueue('crawl_detail');
    }

    public function uniqueId(): string
    {
        return (string) $this->procurementId;
    }

    public function uniqueFor(): int
    {
        return 360;
    }

    public function handle(DetailAnalysisProcessor $processor, PipelineDispatcher $dispatcher): void
    {
        $procurement = Procurement::findOrFail($this->procurementId);

        $deadlineSeconds = config('crawler.detail_deadline_seconds', 270);

        if (! is_int($deadlineSeconds) || $deadlineSeconds < 1) {
            throw new \UnexpectedValueException('crawler.detail_deadline_seconds must be a positive integer.');
        }

        if (! $processor->run(procurement: $procurement, deadlineAt: microtime(true) + $deadlineSeconds)) {
            return;
        }

        $dispatcher->dispatch(procurement: $procurement);
    }

    public function failed(?Throwable $exception): void
    {
        app(DetailAnalysisProcessor::class)->recordFailedJob(
            procurementId: $this->procurementId,
            error: $exception?->getMessage() ?? 'Detail queue execution failed before completion.',
        );
    }
}
