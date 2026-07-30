<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Enums\Procurement\PromptSuggestionKind;
use App\Enums\Procurement\PromptSuggestionStatus;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptSuggestion>
 */
final class PromptSuggestionFactory extends Factory
{
    protected $model = PromptSuggestion::class;

    public function definition(): array
    {
        return [
            'kind' => PromptSuggestionKind::PromptPatch,
            'status' => PromptSuggestionStatus::Pending,
            'analysis_id' => null,
            'fixture_id' => null,
            'eval_run_id' => null,
            'llm_call_id' => null,
            'prompt_key' => EstimatePromptStep::ExtractionRows->value,
            'prompt_step' => EstimatePromptStep::ExtractionRows->value,
            'base_version' => 1,
            'proposed_content' => $this->faker->paragraph(),
            'hint' => null,
            'document_type' => AiDocumentType::Estimate->value,
            'analysis_step' => AnalysisStep::Extraction,
            'rationale' => $this->faker->sentence(),
            'evidence' => ['rows[0].code'],
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            'applied_at' => null,
            'applied_version' => null,
            'reverted_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromptSuggestionStatus::Approved,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function correctionHint(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PromptSuggestionKind::CorrectionHint,
            'prompt_key' => null,
            'prompt_step' => null,
            'base_version' => null,
            'proposed_content' => null,
            'hint' => $this->faker->sentence(),
            'document_type' => 'estimate',
        ]);
    }
}
