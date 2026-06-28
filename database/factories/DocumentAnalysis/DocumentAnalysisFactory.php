<?php

declare(strict_types=1);

namespace Database\Factories\DocumentAnalysis;

use App\Enums\AnalysisStatus;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAnalysis>
 */
final class DocumentAnalysisFactory extends Factory
{
    protected $model = DocumentAnalysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'https://example.com/doc-'.fake()->unique()->numerify('#######').'.xlsx',
            'status' => AnalysisStatus::Preparing,
        ];
    }

    public function withStatus(AnalysisStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function prepared(): static
    {
        return $this->withStatus(AnalysisStatus::Prepared);
    }

    public function primaryAnalyzing(): static
    {
        return $this->withStatus(AnalysisStatus::PrimaryAnalyzing);
    }

    public function primaryAnalyzed(): static
    {
        return $this->withStatus(AnalysisStatus::PrimaryAnalyzed);
    }

    public function primaryAnalysisFailed(): static
    {
        return $this->withStatus(AnalysisStatus::PrimaryAnalysisFailed);
    }

    public function estimateAnalyzing(): static
    {
        return $this->withStatus(AnalysisStatus::EstimateAnalyzing);
    }

    public function estimateAnalyzed(): static
    {
        return $this->withStatus(AnalysisStatus::EstimateAnalyzed);
    }

    public function estimateAnalysisFailed(): static
    {
        return $this->withStatus(AnalysisStatus::EstimateAnalysisFailed);
    }

    public function completed(): static
    {
        return $this->withStatus(AnalysisStatus::Completed);
    }

    /** Терминальный провал: исчерпаны попытки (dead-letter), retry не трогает. */
    public function deadLetter(): static
    {
        return $this->state(fn (): array => ['failed_permanently' => true]);
    }

    /** Привязка анализа к вложению (полиморфная связь model_type/model_id + источник). */
    public function forAttachment(LinkAttachment $attachment): static
    {
        return $this->state(fn (): array => [
            'model_type' => $attachment->getMorphClass(),
            'model_id' => $attachment->getKey(),
            'source' => $attachment->url,
        ]);
    }

    /** Активная лиза: запись захвачена воркером на время обработки. */
    public function leasedBy(string $owner): static
    {
        return $this->state(fn (): array => [
            'locked_by' => $owner,
            'locked_until' => now()->addMinutes(5),
        ]);
    }

    /** Истёкшая лиза: воркер «умер», запись свободна для перезахвата. */
    public function leaseExpired(string $owner = 'dead-worker'): static
    {
        return $this->state(fn (): array => [
            'locked_by' => $owner,
            'locked_until' => now()->subMinute(),
        ]);
    }
}
