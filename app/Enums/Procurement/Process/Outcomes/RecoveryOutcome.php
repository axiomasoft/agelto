<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

/** Исход одной locked recovery транзакции одного work: что делать reconciler дальше. */
enum RecoveryOutcome
{
    /** Состояние изменено (или применён settlement). */
    case Recovered;
    /** Locked predicates не выполнены либо work занят другим backend: ничего не записано. */
    case Skipped;
    /** Snapshot authority изменился между pre-read и locks: rollback и новый pre-read. */
    case Reload;
    /** Group/membership повреждены: incident, без молчаливого repair. */
    case Corrupt;
}
