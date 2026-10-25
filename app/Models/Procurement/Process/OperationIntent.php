<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Durable намерение операции над subject (execution#s10): intent_key unique, один active на
 * (stage, subject). Создаётся до work, work ссылается на intent FK.
 *
 * @property string $id
 * @property mixed $intent_key
 * @property StageCode $stage
 * @property string $subject_kind
 * @property string $subject_id
 * @property mixed $semantic_policy_hash
 * @property mixed $access_policy_hash
 * @property int $cycle_seq
 * @property int $expected_head_generation
 * @property IntentState $state
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $next_due_at
 */
#[Table(name: 'prc_operation_intents', keyType: 'string', incrementing: false, timestamps: false)]
final class OperationIntent extends Model
{
    /** @return HasMany<WorkItem, $this> */
    public function work(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'intent_id');
    }

    protected function casts(): array
    {
        return [
            'stage' => StageCode::class,
            'state' => IntentState::class,
            'requested_at' => 'immutable_datetime',
            'next_due_at' => 'immutable_datetime',
        ];
    }
}
