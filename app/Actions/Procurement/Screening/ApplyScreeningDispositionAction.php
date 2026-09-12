<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Screening;

use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use Illuminate\Support\Facades\DB;

/** The sole short transaction that turns validated screening evidence into domain state (D34). */
final readonly class ApplyScreeningDispositionAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private ProcurementStoreRepository $procurements,
        private UsageSnapshotRecorder $usageSnapshots,
    ) {}

    public function execute(
        Document $document,
        Analysis $analysis,
        ScreeningResult $result,
        ScreeningDecision $decision,
        ?DocumentAnalyzer $analyzer,
    ): bool {
        $leaseToken = $analysis->leaseToken();

        if ($leaseToken === null) {
            return false;
        }

        return DB::transaction(function () use ($document, $analysis, $result, $decision, $analyzer, $leaseToken): bool {
            if ($analysis->is_shadow) {
                $lockedAnalysis = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

                if (! $this->ownsLockedAnalysis($lockedAnalysis, $leaseToken)
                    || $lockedAnalysis->status !== AnalysisStatus::Running || ! $lockedAnalysis->is_shadow) {
                    return false;
                }

                $shadowDecision = new ScreeningDecision(
                    disposition: $decision->disposition,
                    reason: $decision->reason,
                    extractionDispatched: false,
                );
                $stored = new StoredScreeningResult(
                    confidence: $result->confidence,
                    summary: $result->summary,
                    estimateCardinality: $result->estimate_cardinality,
                    tags: $result->tags,
                    classificationNote: $result->classification_note,
                    pageCountEstimate: null,
                    decision: $shadowDecision,
                );

                $this->usageSnapshots->finalizeAnalysis($analysis, [
                    'status' => AnalysisStatus::Succeeded,
                    'document_type' => $result->document_type->value,
                    'result' => $stored->toArray(),
                    'finished_at' => now(),
                ]);
                $this->usageSnapshots->markRunSucceeded($analysis->analysisRun);

                return true;
            }

            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $lockedAnalysis = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

            if (! $this->ownsLockedAnalysis($lockedAnalysis, $leaseToken)
                || $lockedDocument->status !== DocumentStatus::Prepared
                || $lockedAnalysis->status !== AnalysisStatus::Running || $lockedAnalysis->is_shadow) {
                return false;
            }

            $stored = new StoredScreeningResult(
                confidence: $result->confidence,
                summary: $result->summary,
                estimateCardinality: $result->estimate_cardinality,
                tags: $result->tags,
                classificationNote: $result->classification_note,
                pageCountEstimate: null,
                decision: $decision,
            );

            $this->usageSnapshots->finalizeAnalysis($analysis, [
                'status' => AnalysisStatus::Succeeded,
                'document_type' => $result->document_type->value,
                'result' => $stored->toArray(),
                'finished_at' => now(),
            ]);

            $this->documents->update($lockedDocument, [
                'status' => match ($decision->disposition) {
                    ScreeningDisposition::Ignore => DocumentStatus::Ignored,
                    default => DocumentStatus::Screened,
                },
            ]);

            $this->storeEvent($lockedDocument, $analysis, $result, $decision);

            if (! $decision->extractionDispatched) {
                $this->usageSnapshots->markRunSucceeded($analysis->analysisRun);
            }

            if ($decision->extractionDispatched && $analyzer !== null) {
                DB::afterCommit(function () use ($analyzer, $lockedDocument): void {
                    $analyzer->dispatchExtraction($lockedDocument->id);
                });
            }

            return true;
        });
    }

    private function ownsLockedAnalysis(Analysis $analysis, string $leaseToken): bool
    {
        return $analysis->locked_by === $leaseToken
            && $analysis->locked_until !== null
            && Analysis::query()
                ->whereKey($analysis->getKey())
                ->where('locked_by', $leaseToken)
                ->whereRaw('locked_until >= CURRENT_TIMESTAMP')
                ->exists();
    }

    private function storeEvent(Document $document, Analysis $analysis, ScreeningResult $result, ScreeningDecision $decision): void
    {
        if ($document->procurement_id === null) {
            return;
        }

        $this->procurements->storeEvent(
            procurementId: $document->procurement_id,
            eventType: ProcurementEventType::DocumentScreened,
            occurredAt: now(),
            entityRef: ['document_id' => $document->id, 'analysis_id' => $analysis->id],
            newValue: $decision->disposition === ScreeningDisposition::Extract ? $result->document_type->value : $decision->disposition->value,
            payload: [
                'document_type' => $result->document_type->value,
                'confidence' => $result->confidence,
                'disposition' => $decision->disposition->value,
                'reason' => $decision->reason->value,
            ],
        );
    }
}
