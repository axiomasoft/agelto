<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно владение work: unique(work_id, generation), никогда не перезаписывается другим attempt.
 * Сам token хранится только в work.owner_token; здесь sha256 fingerprint. Finished attempts с
 * fault_signature — authority per-work handler fault budget (execution#handler-fault-budget).
 *
 * @property string $id
 * @property string $work_id
 * @property int $generation
 * @property mixed $owner_token_fingerprint
 * @property string $handler_version
 * @property mixed $fault_signature
 * @property CarbonImmutable|null $fault_policy_checked_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $last_checkpoint_at
 * @property string|null $terminal_kind
 * @property string $worker_host
 * @property int $worker_pid
 * @property-read WorkItem $work
 */
#[Table(name: 'prc_process_attempts', keyType: 'string', incrementing: false, timestamps: false)]
final class WorkAttempt extends Model
{
    /** @return BelongsTo<WorkItem, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_id');
    }

    protected function casts(): array
    {
        return [
            'fault_policy_checked_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'last_checkpoint_at' => 'immutable_datetime',
        ];
    }
}
