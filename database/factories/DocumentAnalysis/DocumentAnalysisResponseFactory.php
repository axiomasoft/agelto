<?php

declare(strict_types=1);

namespace Database\Factories\DocumentAnalysis;

use App\Dto\Prompt\Step;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\DocumentAnalysis\DocumentAnalysisResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAnalysisResponse>
 */
final class DocumentAnalysisResponseFactory extends Factory
{
    protected $model = DocumentAnalysisResponse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_analysis_id' => DocumentAnalysis::factory(),
            'step' => Step::PrimaryAnalysis,
            'prompt' => 'анализ',
            'success' => true,
        ];
    }
}
