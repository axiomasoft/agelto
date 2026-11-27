<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance link run↔work, PRIMARY KEY (run_id, work_id). Read model без собственного key:
 * Eloquent save/delete не используются, writer — WorkStoreRepository::linkRun.
 *
 * @property string $run_id
 * @property string $work_id
 * @property RunWorkRelation $relation
 * @property AccountingRole $accounting_role
 * @property-read ProcessRun $run
 * @property-read WorkItem $work
 */
#[Table(name: 'prc_process_run_work', key: 'run_id', keyType: 'string', incrementing: false, timestamps: false)]
final class RunWork extends Model
{
    /** @return BelongsTo<ProcessRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessRun::class, 'run_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_id');
    }

    protected function casts(): array
    {
        return [
            'relation' => RunWorkRelation::class,
            'accounting_role' => AccountingRole::class,
        ];
    }
}
