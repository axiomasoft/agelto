<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceFailure;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceBreakerOutcome;
use App\Enums\Procurement\Crawler\SourceStage;

/**
 * Единая точка, куда CSV-обход и обработка карточек сообщают об отказе/успехе. Сами они не
 * считают, не пишут журнал и не логируют. Здесь: учёт брейкером → событие отказа → событие об
 * ожидании/остановке этапа, если этот отказ их вызвал.
 *
 * Вызывать вне транзакции блока/карточки: и счётчик, и журнал должны пережить её откат.
 */
final readonly class SourceFailureReporter
{
    public function __construct(
        private SourceCircuitBreaker $breaker,
        private SourceEventReporter $events,
    ) {}

    /** @return bool принимает ли этап запросы после этого отказа */
    public function report(SourceFailure $failure): bool
    {
        $outcome = $this->breaker->recordFailure($failure->platformId, $failure->stage, $failure->kind);

        $this->event(CrawlerEventCode::forFailure($failure->stage, $failure->kind), $failure, $failure->detail);

        match ($outcome) {
            SourceBreakerOutcome::StartedWaiting => $this->event(CrawlerEventCode::StageWaiting, $failure,
                "{$failure->stage->getLabel()}: {$failure->kind->label()} — серия отказов, этап ждёт и повторит сам."),
            SourceBreakerOutcome::StartedHold => $this->event(CrawlerEventCode::StageHeld, $failure,
                "{$failure->stage->getLabel()}: изменилась структура источника — этап остановлен до решения оператора."),
            default => null,
        };

        return $outcome->admits();
    }

    public function reportSuccess(int $platformId, SourceStage $stage): void
    {
        $this->breaker->recordSuccess($platformId, $stage);
    }

    private function event(CrawlerEventCode $code, SourceFailure $failure, string $message): void
    {
        $this->events->report(
            code: $code,
            platformId: $failure->platformId,
            message: $message,
            stage: $failure->stage,
            crawlerId: $failure->crawlerId,
            runId: $failure->runId,
            fetchId: $failure->fetchId,
            failureKind: $failure->kind,
            httpStatus: $failure->httpStatus,
            cursor: $failure->cursor,
            requestParams: $failure->requestParams,
            context: $failure->context,
            actor: in_array($code, [CrawlerEventCode::StageWaiting, CrawlerEventCode::StageHeld], true) ? SourceCircuitBreaker::SystemActor : null,
        );
    }
}
