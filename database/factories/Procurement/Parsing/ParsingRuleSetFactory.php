<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Parsing;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParsingRuleSet>
 */
final class ParsingRuleSetFactory extends Factory
{
    protected $model = ParsingRuleSet::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'platform_id' => Platform::factory(),
            'stage' => StageCode::Listing,
            'registry_code' => null,
            'scope_key' => 'default',
            'active_version_id' => null,
            'current_version_number' => 0,
            'auto_heal_mode' => AutoHealMode::Propose,
            'description' => null,
            'is_enabled' => true,
        ];
    }
}
