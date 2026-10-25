<?php

declare(strict_types=1);

namespace App\Models\Procurement\Network;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт результата запроса/контракта/парсинга, включая stale; health projection применяет его
 * один раз через ObservationApplication. Writer — P2.1–P2.6.
 *
 * @property string $observation_id
 * @property string|null $request_id
 * @property string|null $attempt_id
 * @property string $kind
 * @property string $reason_code
 * @property mixed $input_hash
 * @property int $generation
 * @property array<string, mixed> $epochs
 * @property int $epochs_schema_version
 * @property bool $stale
 * @property array<string, mixed>|null $details
 * @property int|null $details_schema_version
 * @property CarbonImmutable $occurred_at
 * @property-read RequestAttempt|null $request
 */
#[Table(name: 'prc_observations', key: 'observation_id', keyType: 'string', incrementing: false, timestamps: false)]
final class Observation extends Model
{
    /** @return BelongsTo<RequestAttempt, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RequestAttempt::class, 'request_id');
    }

    protected function casts(): array
    {
        return [
            'epochs' => 'array',
            'details' => 'array',
            'stale' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
