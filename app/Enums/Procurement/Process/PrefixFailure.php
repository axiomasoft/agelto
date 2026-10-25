<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Полный lock prefix не взят: состав/heads изменились после pre-read (rollback + reload, не
 * поздний lock), work занят другим feeder/reconciler (SKIP LOCKED) или исчез.
 */
enum PrefixFailure: string
{
    case SnapshotChanged = 'snapshot_changed';
    case WorkLocked = 'work_locked';
    case WorkMissing = 'work_missing';
}
