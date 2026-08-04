<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Correction>
 */
final class CorrectionFactory extends Factory
{
    protected $model = Correction::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'estimate_id' => null,
            'document_type' => AiDocumentType::Estimate->value,
            'field_path' => null,
            'reason' => CorrectionReason::WrongValue,
            'content' => $this->faker->sentence(),
            'hint' => null,
            'is_reusable' => false,
            'created_by' => User::factory(),
            'applied_at' => null,
        ];
    }
}
