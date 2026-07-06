<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRoute>
 */
final class AiRouteFactory extends Factory
{
    protected $model = AiRoute::class;

    public function definition(): array
    {
        return [
            'document_type' => 'any',
            'purpose' => LlmPurpose::Screening,
            'provider_id' => Provider::factory(),
            'model' => 'gemini-2.5-flash',
            'options' => null,
            'priority' => 0,
            'is_active' => true,
            'shadow' => false,
        ];
    }
}
