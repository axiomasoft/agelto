<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Process\CallerTransaction;

/** Operator review metadata only; accepted data and execution stay with WorkFinalizer. */
final readonly class AnalysisReviewRepository
{
    /** Lock the shared version before its result projection; historical analyses remain reviewable. */
    public function lockForReview(int $analysisId): Analysis
    {
        CallerTransaction::require('Analysis review locks');
        $analysis = Analysis::query()->findOrFail($analysisId);
        DocumentVersion::query()->lockForUpdate()->findOrFail($analysis->document_version_id);

        $locked = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
        if ($locked->document_version_id !== $analysis->document_version_id) {
            throw new \LogicException('Analysis review ancestry changed.');
        }

        return $locked;
    }

    /** @param array<string,mixed> $attributes */
    public function update(Analysis $analysis, array $attributes): void
    {
        if (array_diff(array_keys($attributes), ['review_status', 'reviewed_by', 'reviewed_at', 'operator_comment']) !== []) {
            throw new \InvalidArgumentException('Only operator review metadata may be edited.');
        }
        $analysis->update($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function storeCorrectionLocked(Analysis $analysis, int $reviewerId, string $uuid, array $attributes): void
    {
        CallerTransaction::require('Analysis review correction');
        $fieldPath = $attributes['field_path'] ?? null;
        Correction::query()->create([
            'uuid' => $uuid,
            'analysis_id' => $analysis->id,
            'document_type' => $analysis->document_type,
            'field_path' => $fieldPath === '' ? null : $fieldPath,
            'reason' => $attributes['reason'],
            'content' => $attributes['content'],
            'is_reusable' => (bool) ($attributes['is_reusable'] ?? false),
            'created_by' => $reviewerId,
        ]);
    }
}
