<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use App\Models\Procurement\Process\WorkAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один фактический внешний send внутри work attempt. Route/target FK ссылаются на реальные
 * network rows; send authorization и factual completion сохраняются раздельно.
 *
 * @property string $request_id
 * @property string|null $attempt_id
 * @property string|null $demand_id
 * @property int $request_seq
 * @property string|null $target_id
 * @property string|null $route_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $finished_at
 * @property string $phase
 * @property string|null $outcome
 * @property int $bytes
 * @property int|null $status
 * @property int|null $duration_ms
 * @property array<string, mixed> $epochs
 * @property int $epochs_schema_version
 * @property array<string, mixed>|null $artifacts
 * @property int|null $artifacts_schema_version
 * @property-read WorkAttempt|null $attempt
 */
#[Table(name: 'prc_request_attempts', key: 'request_id', keyType: 'string', incrementing: false, timestamps: false)]
final class RequestAttempt extends Model
{
    /** @return BelongsTo<WorkAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(WorkAttempt::class, 'attempt_id');
    }

    protected function casts(): array
    {
        return [
            'epochs' => 'array',
            'artifacts' => 'array',
            'started_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
