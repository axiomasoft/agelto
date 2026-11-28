<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Parsing;

use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Models\Procurement\Parsing\ParsingRuleEvent;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParsingRuleEvent>
 */
final class ParsingRuleEventFactory extends Factory
{
    protected $model = ParsingRuleEvent::class;

    public function definition(): array
    {
        return [
            'rule_set_id' => ParsingRuleSet::factory(),
            'rule_version_id' => null,
            'event_type' => ParsingRuleEventType::Created,
            'actor_type' => ParsingRuleActorType::System,
            'actor_id' => null,
            'reason' => 'Created in test',
            'metadata' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }
}
