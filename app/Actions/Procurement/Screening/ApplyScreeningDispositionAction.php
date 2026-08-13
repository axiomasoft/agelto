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
        return DB::transaction(function () use ($document, $analysis, $result, $decision, $analyzer): bool {
            if ($analysis->is_shadow) {
                $lockedAnalysis = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

                if ($lockedAnalysis->status !== AnalysisStatus::Running || ! $lockedAnalysis->is_shadow) {
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

                $this->usageSnapshots->finalizeAnalysis($lockedAnalysis, [
                    'status' => AnalysisStatus::Succeeded,
                    'document_type' => $result->document_type->value,
                    'result' => $stored->toArray(),
                    'finished_at' => now(),
                ]);
                $this->usageSnapshots->markRunSucceeded($lockedAnalysis->analysisRun);

                return true;
            }

            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $lockedAnalysis = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

            if ($lockedDocument->status !== DocumentStatus::Prepared || $lockedAnalysis->status !== AnalysisStatus::Running || $lockedAnalysis->is_shadow) {
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

            $this->usageSnapshots->finalizeAnalysis($lockedAnalysis, [
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

            $this->storeEvent($lockedDocument, $lockedAnalysis, $result, $decision);

            if (! $decision->extractionDispatched) {
                $this->usageSnapshots->markRunSucceeded($lockedAnalysis->analysisRun);
            }

            if ($decision->extractionDispatched && $analyzer !== null) {
                DB::afterCommit(function () use ($analyzer, $lockedDocument): void {
                    $analyzer->dispatchExtraction($lockedDocument->id);
                });
            }

            return true;
        });
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
