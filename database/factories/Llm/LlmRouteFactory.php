<?php

declare(strict_types=1);

namespace Database\Factories\Llm;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmRoute>
 */
final class LlmRouteFactory extends Factory
{
    protected $model = LlmRoute::class;

    public function definition(): array
    {
        return [
            'document_type' => DocumentTypeScope::Any->value,
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
