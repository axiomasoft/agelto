<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;

/**
 * Запись `prc_ai_dataset_examples` — `estimate:dataset:import`,
 * `estimate:gold:import`. Ключ апсерта общий, payload — параметр.
 */
final readonly class DatasetExampleStoreRepository
{
    public function __construct(private ProcessTransaction $transactions) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeOrUpdate(int $documentVersionId, string $task, int $version, array $attributes): DatasetExample
    {
        return $this->transactions->run(function () use ($documentVersionId, $task, $version, $attributes): DatasetExample {
            $this->lockVersion($documentVersionId);

            return DatasetExample::query()->updateOrCreate(
                ['document_version_id' => $documentVersionId, 'task' => $task, 'version' => $version],
                $attributes,
            );
        });
    }

    /** @param array<string,mixed> $gold */
    public function recordAnalysisSnapshot(Analysis $analysis, array $gold, string $source): DatasetExample
    {
        return $this->transactions->run(function () use ($analysis, $gold, $source): DatasetExample {
            $this->lockVersion($analysis->document_version_id);
            $existing = DatasetExample::query()->where('analysis_id', $analysis->id)->limit(2)->get();
            if ($existing->count() > 1) {
                throw new \LogicException('Analysis has conflicting gold snapshots.');
            }
            $example = $existing->first();
            if ($example !== null) {
                if ($example->document_version_id !== $analysis->document_version_id || $example->task !== AnalysisStep::Extraction->value) {
                    throw new \LogicException('Analysis gold snapshot identity changed.');
                }

                if ($analysis->review_status === ReviewStatus::Approved) {
                    $example->update([
                        'verified_by' => $analysis->reviewed_by,
                        'verified_at' => $analysis->reviewed_at,
                    ]);
                }

                return $example;
            }
            $maxVersion = DatasetExample::query()
                ->where('document_version_id', $analysis->document_version_id)
                ->where('task', AnalysisStep::Extraction->value)
                ->max('version');
            $version = (is_int($maxVersion) || is_float($maxVersion) || is_string($maxVersion) ? (int) $maxVersion : 0) + 1;

            return DatasetExample::query()->create([
                'document_version_id' => $analysis->document_version_id,
                'analysis_id' => $analysis->id,
                'document_type' => $analysis->document_type ?? AiDocumentType::Estimate->value,
                'task' => AnalysisStep::Extraction->value,
                'gold' => $gold,
                'gold_ref' => null,
                'source' => $source,
                'verified_by' => $analysis->reviewed_by,
                'verified_at' => $analysis->reviewed_at,
                'version' => $version,
            ]);
        });
    }

    public function revokeAnalysisVerification(Analysis $analysis): void
    {
        CallerTransaction::require('Analysis gold verification');

        DatasetExample::query()
            ->where(column: 'analysis_id', operator: $analysis->id)
            ->update(['verified_at' => null, 'verified_by' => null]);
    }

    private function lockVersion(int $documentVersionId): void
    {
        DocumentVersion::query()->lockForUpdate()->findOrFail($documentVersionId);
    }
}
