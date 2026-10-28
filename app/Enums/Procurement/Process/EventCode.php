<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Закрытый каталог событий typed envelope: именно accepted work transition, не логическая строка. */
enum EventCode: string
{
    case WorkSucceeded = 'work.succeeded';
    case WorkPartial = 'work.partial';
    case WorkDeferred = 'work.deferred';
    case WorkBlocked = 'work.blocked';
    case WorkFailedItem = 'work.failed_item';
    case WorkCancelled = 'work.cancelled';
    case WorkSuperseded = 'work.superseded';

    /** Состояние work после finalize → событие; Partial возвращает work в ready. */
    public static function forState(WorkState $state): ?self
    {
        return match ($state) {
            WorkState::Succeeded => self::WorkSucceeded,
            WorkState::Ready => self::WorkPartial,
            WorkState::Deferred => self::WorkDeferred,
            WorkState::Blocked => self::WorkBlocked,
            WorkState::FailedItem => self::WorkFailedItem,
            WorkState::Cancelled => self::WorkCancelled,
            WorkState::Superseded => self::WorkSuperseded,
            WorkState::Admitted, WorkState::Running => null,
        };
    }
}
