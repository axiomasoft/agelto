<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\ProcessRunState;

/**
 * Прогресс одного run: required-счётчики (progress/completion), число observes-link отдельно
 * (downstream provenance), executed_count только relation=created. Пока discovery открыт,
 * ожидаемый итог неизвестен: percent null, а не ложные 100%.
 */
final readonly class RunSummary
{
    public function __construct(
        public ProcessUuid $runId,
        public ProcessRunState $state,
        public DiscoveryOutcome $discovery,
        public WorkStateCounts $required,
        public int $observes,
        public int $executed,
    ) {
        ProcessValue::nonNegative($observes);
        ProcessValue::nonNegative($executed);
    }

    /** Все ли required work обнаружены: только после закрытия discovery planned — финальное число. */
    public function plannedFinal(): bool
    {
        return $this->discovery->closed();
    }

    public function percent(): ?int
    {
        if (! $this->plannedFinal()) {
            return null;
        }
        $total = $this->required->total();

        return $total === 0 ? 100 : intdiv($this->required->terminal() * 100, $total);
    }
}
