<?php

declare(strict_types=1);

namespace App\Models\Procurement\Process;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable намерение доставки: dedup_key unique, отдельные consumers по kind (work_wake/
 * feeder_wake/projection) с token CAS claim. Сеть никогда не под outbox lock.
 *
 * @property string $message_id
 * @property string $dedup_key
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property int $payload_schema_version
 * @property CarbonImmutable $available_at
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claim_until
 * @property CarbonImmutable|null $published_at
 * @property int $attempts
 * @property string|null $last_error_code
 */
#[Table(name: 'prc_process_outbox', key: 'message_id', keyType: 'string', incrementing: false, timestamps: false)]
final class OutboxMessage extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'claim_until' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
