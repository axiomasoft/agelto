<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Models\Procurement\Ai\Analysis;

/** Operator review metadata only; accepted data and execution stay with WorkFinalizer. */
final readonly class AnalysisReviewRepository
{
    /** @param array<string,mixed> $attributes */
    public function update(Analysis $analysis, array $attributes): void
    {
        if (array_diff(array_keys($attributes), ['review_status', 'reviewed_by', 'reviewed_at', 'operator_comment']) !== []) {
            throw new \InvalidArgumentException('Only operator review metadata may be edited.');
        }
        $analysis->update($attributes);
    }
}
