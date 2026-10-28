<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Единый исход typed operator command для UI/CLI. Applied — единственный вариант, изменивший
 * ledger; Unchanged/Duplicate — идемпотентный повтор; остальные — отказ без записей.
 */
enum OperatorCommandStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Duplicate = 'duplicate';
    case VersionConflict = 'version_conflict';
    case NotFound = 'not_found';
    case Terminal = 'terminal';
    case NotEligible = 'not_eligible';
    case ProviderUnresolved = 'provider_unresolved';
    case SnapshotChanged = 'snapshot_changed';

    public static function fromWork(WorkMutationStatus $status): self
    {
        return match ($status) {
            WorkMutationStatus::Applied => self::Applied,
            WorkMutationStatus::NotFound => self::NotFound,
            WorkMutationStatus::VersionConflict => self::VersionConflict,
            WorkMutationStatus::Terminal => self::Terminal,
            WorkMutationStatus::Duplicate => self::Duplicate,
            WorkMutationStatus::NotEligible => self::NotEligible,
            WorkMutationStatus::ProviderUnresolved => self::ProviderUnresolved,
            WorkMutationStatus::SnapshotChanged => self::SnapshotChanged,
        };
    }

    public static function fromControl(ControlChangeStatus $status): self
    {
        return match ($status) {
            ControlChangeStatus::Applied => self::Applied,
            ControlChangeStatus::Unchanged => self::Unchanged,
            ControlChangeStatus::VersionConflict => self::VersionConflict,
            ControlChangeStatus::NotFound => self::NotFound,
        };
    }

    /** Команда дошла до ledger и оставила его согласованным: UI не показывает ошибку. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Applied, self::Unchanged, self::Duplicate], true);
    }
}
