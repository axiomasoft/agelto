<?php

declare(strict_types=1);

namespace Database\Factories\Platform;

use App\Models\Platform\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
final class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->word(),
            'name' => fake()->words(asText: true),
            'has_extractor' => false,
            'is_active' => true,
        ];
    }
}
