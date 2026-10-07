<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Dto\Procurement\Crawler\SourceEvent;
use App\Dto\Procurement\Crawler\SourceFailure;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceBreakerOutcome;
use App\Enums\Procurement\Crawler\SourceFailureReaction;
use App\Enums\Procurement\Crawler\SourceStage;

/**
 * Единая точка, куда CSV-обход и обработка карточек сообщают об отказе/успехе. Сами они не
 * считают, не пишут журнал и не логируют. Здесь: реакция из `SourceControlPolicy` → учёт
 * брейкером → событие отказа (журнал+лог или только лог) → событие об ожидании/остановке.
 *
 * Вызывать вне транзакции блока/карточки: и счётчик, и журнал должны пережить её откат.
 */
final readonly class SourceFailureReporter
{
    public function __construct(
        private SourceControlPolicy $policy,
        private SourceCircuitBreaker $breaker,
        private SourceEventReporter $events,
    ) {}

    /** @return bool принимает ли этап запросы после этого отказа */
    public function report(SourceFailure $failure): bool
    {
        $reaction = $this->policy->stage($failure->stage)->reactionFor($failure->kind);
        $event = SourceEvent::forFailure(CrawlerEventCode::forFailure($failure->stage, $failure->kind), $failure, $failure->detail);

        $outcome = $this->breaker->recordFailure($failure->platformId, $failure->stage, $failure->kind, $reaction);

        if ($reaction === SourceFailureReaction::LogOnly) {
            $this->events->log($event);
        } else {
            $this->events->report($event);
        }

        match ($outcome) {
            SourceBreakerOutcome::StartedWaiting => $this->events->report(SourceEvent::forFailure(
                CrawlerEventCode::StageWaiting, $failure,
                "{$failure->stage->getLabel()}: {$failure->kind->label()} — серия отказов, этап ждёт и повторит сам.",
                SourceCircuitBreaker::SystemActor,
            )),
            SourceBreakerOutcome::StartedHold => $this->events->report(SourceEvent::forFailure(
                CrawlerEventCode::StageHeld, $failure,
                "{$failure->stage->getLabel()}: изменилась структура источника — этап остановлен до решения оператора.",
                SourceCircuitBreaker::SystemActor,
            )),
            SourceBreakerOutcome::StartedSkipHold => $this->events->report(SourceEvent::forFailure(
                CrawlerEventCode::StageHeld, $failure,
                "{$failure->stage->getLabel()}: подряд пропущено слишком много блоков без единого успеха (последний — {$failure->kind->label()}) — этап остановлен до решения оператора.",
                SourceCircuitBreaker::SystemActor,
            )),
            SourceBreakerOutcome::Running, SourceBreakerOutcome::NotAdmitting => null,
        };

        return $outcome->admits();
    }

    public function reportSuccess(int $platformId, SourceStage $stage): void
    {
        $this->breaker->recordSuccess($platformId, $stage);
    }
}
