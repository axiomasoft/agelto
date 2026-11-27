<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

/**
 * Закрытый набор исходов управляющих мутаций (CancelWork, CancelRun, RetryHandlerFaultedWork):
 * Applied — единственный вариант, изменивший ledger; остальные — ноль записей.
 */
enum WorkMutationStatus: string
{
    case Applied = 'applied';
    case NotFound = 'not_found';
    case VersionConflict = 'version_conflict';
    /** Work уже terminal либо run уже отменён: история не переписывается. */
    case Terminal = 'terminal';
    /** Та же команда уже применена: повтор не создаёт второй intent/work. */
    case Duplicate = 'duplicate';
    /** Состояние work не допускает эту команду. */
    case NotEligible = 'not_eligible';
    /** Есть нерешённая provider operation: сначала reconciliation, финансовые gates не обходятся. */
    case ProviderUnresolved = 'provider_unresolved';
    case SnapshotChanged = 'snapshot_changed';
}
