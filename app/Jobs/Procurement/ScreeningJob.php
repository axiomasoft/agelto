<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;

/**
 * Заглушка стадии скрининга — полноценный `ScreeningProcessor` (создание `Ai\Analysis`,
 * резка первых страниц, LLM-вызов) реализует P5.1/P5.2 (ТЗ 03 §3.4). Диспатчится
 * `PrepareDocumentJob` по успеху подготовки за гейтом `config('ai.screening.enabled', false)`
 * (владелец цепочки стадий — P4.2); за флагом, выключенным по умолчанию, до P5 в бою
 * не исполняется.
 */
final class ScreeningJob extends PipelineJob implements ShouldBeUnique
{
    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue('ai_screening');
    }

    public function uniqueId(): string
    {
        return $this->pipelineUniqueId();
    }

    public function pipelineUniqueId(): string
    {
        return "{$this->documentId}:screening";
    }

    protected function statusGuardPassed(): bool
    {
        return true;
    }

    protected function acquireLease(): bool
    {
        return true;
    }

    protected function releaseLease(): void
    {
        //
    }

    protected function recordFailure(Throwable $exception): void
    {
        //
    }

    protected function process(): void
    {
        // P5.1 реализует ScreeningProcessor поверх Ai\Analysis (lease/status-guard свои).
    }
}
