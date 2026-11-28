<?php

declare(strict_types=1);

namespace App\Models\Llm;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One physical provider operation. Consumer relations belong to the consumer; RequestLedger owns writes.
 *
 * @property string $physical_call_id
 * @property string $logical_request_id
 * @property string $model
 * @property string $usage_status
 * @property string $receipt_state
 * @property string|null $receipt_disk
 * @property string|null $receipt_key
 * @property string|null $cost_actual
 * @property string $cost_estimate
 * @property string $provider
 * @property string $purpose
 * @property CarbonImmutable|null $called_at
 * @property int|null $prompt_tokens
 * @property int|null $cached_tokens
 * @property int|null $completion_tokens
 * @property int|null $cost_micros
 * @property int $reserved_micros
 * @property bool|null $success
 */
#[Table(name: 'llm_provider_calls', key: 'physical_call_id', keyType: 'string', incrementing: false, timestamps: false)]
final class LlmCall extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'response_at' => 'immutable_datetime', 'send_claimed_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime', 'usage' => 'array', 'cost_estimate' => 'decimal:8', 'cost_actual' => 'decimal:8',
            'prompt_tokens' => 'integer', 'cached_tokens' => 'integer', 'completion_tokens' => 'integer',
            'cost_micros' => 'integer', 'reserved_micros' => 'integer', 'success' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::saving(static function (): never {
            throw new \LogicException('Provider facts require RequestLedger.');
        });
        self::deleting(static function (): never {
            throw new \LogicException('Provider facts require financial retention authority.');
        });
    }
}
