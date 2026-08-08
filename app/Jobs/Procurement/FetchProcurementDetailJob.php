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

        if (! $processor->run(procurement: $procurement)) {
            return;
        }

        $dispatcher->dispatch(procurement: $procurement);
    }
}
