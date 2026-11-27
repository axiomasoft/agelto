<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Projection\RunFacts;
use App\Enums\Procurement\Process\Core\DiscoveryOutcome;
use App\Enums\Procurement\Process\Core\ProcessRunState;

/**
 * Точный reducer состояния конечного run. Чистая функция authoritative фактов:
 * не читает события, поэтому порядок и дубликаты доставки не влияют на результат, а позднее
 * событие не возвращает статус назад. Пока discovery открыт (collecting), ноль required work не
 * означает completed. Explicit cancel терминален: поздний receipt или чужой shared work его не меняет.
 *
 * Порядок правил: cancel → есть продвигающийся required (ready/admitted/running) → running; иначе
 * есть ожидающий (deferred/blocked) → waiting; иначе все required terminal: discovery открыт →
 * running (requested до первой активации); закрыт → completed, либо completed_with_issues при
 * failed_item, индивидуально отменённом/superseded без замены required work или incomplete discovery.
 * Superseded required link с заменой уже демотирован до observes атомарно, поэтому оставшийся
 * required superseded означает отсутствие successor и не является успехом.
 */
final class RunStateReducer
{
    public function reduce(RunFacts $facts): ProcessRunState
    {
        if ($facts->cancelRequested) {
            return ProcessRunState::Cancelled;
        }
        $required = $facts->required;
        if ($required->active() > 0) {
            return ProcessRunState::Running;
        }
        if ($required->waiting() > 0) {
            return ProcessRunState::Waiting;
        }
        if ($facts->discovery === DiscoveryOutcome::Collecting) {
            return $facts->activated ? ProcessRunState::Running : ProcessRunState::Requested;
        }
        $issues = $required->failedItem + $required->cancelled + $required->superseded > 0
            || $facts->discovery === DiscoveryOutcome::Incomplete;

        return $issues ? ProcessRunState::CompletedWithIssues : ProcessRunState::Completed;
    }
}
