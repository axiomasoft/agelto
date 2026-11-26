<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Ai;

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Repositories\Procurement\Ai\AnalysisReviewRepository;
use App\Repositories\Procurement\Ai\DatasetExampleStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\GoldExampleRecorder;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Str;

/** Operator review and its gold/correction commit together; accepted execution data stays immutable. */
final readonly class ReviewAnalysisAction
{
    public function __construct(
        private AnalysisReviewRepository $reviews,
        private GoldExampleRecorder $gold,
        private ProcessTransaction $transactions,
        private DatasetExampleStoreRepository $examples,
    ) {}

    /** False means already approved; its original reviewer audit is preserved. */
    public function approve(int $analysisId, int $reviewerId): bool
    {
        return $this->transactions->run(function () use ($analysisId, $reviewerId): bool {
            $analysis = $this->reviews->lockForReview($analysisId);
            if ($analysis->status !== AnalysisStatus::Succeeded) {
                throw new \InvalidArgumentException('Only succeeded analyses may be approved.');
            }
            $changed = $analysis->review_status !== ReviewStatus::Approved;
            if ($changed) {
                $this->reviews->update($analysis, [
                    'review_status' => ReviewStatus::Approved,
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                ]);
            }
            // Replays reuse the linked gold, or complete a historical approval missing its snapshot.
            // Screening and extraction without estimates intentionally produce no gold.
            $this->gold->record($analysis);

            return $changed;
        });
    }

    /**
     * Fresh submissions may add corrections as before; UUID is stable only within this SQL retry.
     *
     * @param  array<string,mixed>  $correction
     */
    public function rejectWithCorrection(int $analysisId, int $reviewerId, array $correction): bool
    {
        $correctionUuid = (string) Str::uuid7();

        return $this->transactions->run(function () use ($analysisId, $reviewerId, $correction, $correctionUuid): bool {
            $analysis = $this->reviews->lockForReview($analysisId);
            if ($analysis->status !== AnalysisStatus::Succeeded || $analysis->document_type === null) {
                return false;
            }
            $this->reviews->storeCorrectionLocked($analysis, $reviewerId, $correctionUuid, $correction);
            $this->reviews->update($analysis, [
                'review_status' => ReviewStatus::Rejected,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'operator_comment' => $correction['content'],
            ]);
            $this->examples->revokeAnalysisVerification($analysis);

            return true;
        });
    }
}
