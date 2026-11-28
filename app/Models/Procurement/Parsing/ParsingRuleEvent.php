<?php

declare(strict_types=1);

namespace App\Models\Procurement\Parsing;

use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use Carbon\CarbonImmutable;
use Database\Factories\Procurement\Parsing\ParsingRuleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only событие жизненного цикла набора правил парсинга.
 *
 * @property int $id
 * @property int $rule_set_id
 * @property int|null $rule_version_id
 * @property ParsingRuleEventType $event_type
 * @property ParsingRuleActorType $actor_type
 * @property int|null $actor_id
 * @property string $reason
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 * @property-read ParsingRuleSet $ruleSet
 * @property-read ParsingRuleVersion|null $ruleVersion
 */
#[UseFactory(ParsingRuleEventFactory::class)]
#[Fillable([
    'rule_set_id',
    'rule_version_id',
    'event_type',
    'actor_type',
    'actor_id',
    'reason',
    'metadata',
    'occurred_at',
])]
#[Table(name: 'prc_parsing_rule_events')]
final class ParsingRuleEvent extends Model
{
    /** @use HasFactory<ParsingRuleEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event_type' => ParsingRuleEventType::class,
            'actor_type' => ParsingRuleActorType::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ParsingRuleSet, $this>
     */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ParsingRuleSet::class, 'rule_set_id');
    }

    /**
     * @return BelongsTo<ParsingRuleVersion, $this>
     */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(ParsingRuleVersion::class, 'rule_version_id');
    }
}
