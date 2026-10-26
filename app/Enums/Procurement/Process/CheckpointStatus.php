<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Закрытый набор исходов WorkStore::checkpoint (execution#s03); только Saved сохранил progress. */
enum CheckpointStatus: string
{
    case Saved = 'saved';
    case LostOwnership = 'lost_ownership';
    case StaleCheckpoint = 'stale_checkpoint';
    case InputSuperseded = 'input_superseded';
}
