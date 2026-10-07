<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Models\Procurement\Crawler\SourceCircuit;

/** Действия оператора из админки над этапом: состояние меняет брейкер, событие — репортер. */
final readonly class SourceStageOperations
{
    public function __construct(
        private SourceCircuitBreaker $breaker,
        private SourceEventReporter $events,
    ) {}

    public function pause(SourceCircuit $circuit, string $actor, ?string $reason = null): void
    {
        $this->breaker->pause($circuit, $actor);
        $this->events->report(
            code: CrawlerEventCode::ManualPause,
            platformId: $circuit->platform_id,
            message: "{$circuit->stage->getLabel()}: пауза оператором.".($reason === null || $reason === '' ? '' : " {$reason}"),
            stage: $circuit->stage,
            actor: $actor,
        );
    }

    public function resume(SourceCircuit $circuit, string $actor): void
    {
        $this->breaker->resume($circuit, $actor);
        $this->events->report(
            code: CrawlerEventCode::ManualResume,
            platformId: $circuit->platform_id,
            message: "{$circuit->stage->getLabel()}: возобновлено оператором.",
            stage: $circuit->stage,
            actor: $actor,
        );
    }
}
