<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceEvent;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;

/** Действия оператора из админки над этапом: состояние меняет брейкер, событие — репортер. */
final readonly class SourceStageOperations
{
    public function __construct(
        private SourceCircuitBreaker $breaker,
        private SourceEventReporter $events,
        private SuspendedRunResumer $runs,
    ) {}

    public function pause(SourceCircuit $circuit, string $actor, ?string $reason = null): void
    {
        $this->breaker->pause($circuit, $actor);
        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::ManualPause,
            platformId: $circuit->platform_id,
            message: "{$circuit->stage->getLabel()}: пауза оператором.".($reason === null || $reason === '' ? '' : " {$reason}"),
            stage: $circuit->stage,
            actor: $actor,
        ));
    }

    /**
     * «Возобновить сейчас»: снять паузу/остановку/ожидание и сразу поднять приостановленные
     * прогоны листинга (этап карточек прогонов не держит).
     *
     * @return int сколько прогонов поставлено в очередь
     */
    public function resume(SourceCircuit $circuit, string $actor): int
    {
        $this->breaker->resume($circuit, $actor);
        $this->events->report(new SourceEvent(
            code: CrawlerEventCode::ManualResume,
            platformId: $circuit->platform_id,
            message: "{$circuit->stage->getLabel()}: возобновлено оператором.",
            stage: $circuit->stage,
            actor: $actor,
        ));

        return $circuit->stage === SourceStage::Listing
            ? $this->runs->resumePlatform(platformId: $circuit->platform_id, actor: $actor)
            : 0;
    }
}
