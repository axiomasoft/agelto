<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Enums\Ai\LlmPurpose;
use App\Models\Ai\AiRoute;
use App\Models\Ai\Provider;
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
