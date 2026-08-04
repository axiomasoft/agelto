<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Exceptions\Procurement\AnalysisRunAlreadyActive;
use App\Exceptions\Procurement\ScreeningDisabled;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\AnalysisReadRepository;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use Illuminate\Support\Facades\DB;

/**
 * Действие «Прогнать» на карточке эталона (P5.2, D41/D135/D136): рабочая копия сеймом
 * `WorkingCopyProvisioner` (D41) → штатный вход `PrepareDocumentJob` с режимом `manual` и
 * инициатором (D135) — Action САМ прогон не заводит. На повторном прогоне той же версии снимает
 * активные анализы обоих шагов и откатывает статус документа к `downloaded` (D136), иначе штатные
 * status-guard'ы сделали бы передиспатч no-op.
 */
final readonly class RunFixtureAnalysisAction
{
    public function __construct(
        private WorkingCopyProvisioner $provisioner,
        private AnalysisRunStoreRepository $runs,
        private AnalysisReadRepository $analysisReads,
        private AnalysisStoreRepository $analyses,
        private DocumentStoreRepository $documents,
    ) {}

    public function run(Fixture $fixture, ?int $initiatedBy = null): void
    {
        if (! config('ai.screening.enabled', false)) {
            throw new ScreeningDisabled;
        }

        [$document, $version] = $this->provisioner->provision($fixture, dryRun: false);

        if ($this->runs->findActive($version->id) !== null) {
            throw new AnalysisRunAlreadyActive;
        }

        DB::transaction(function () use ($document, $version, $initiatedBy): void {
            foreach ([AnalysisStep::Screening, AnalysisStep::Extraction] as $step) {
                $analysis = $this->analysisReads->activeForVersion($version->id, $step);

                if ($analysis !== null) {
                    $this->analyses->update($analysis, ['superseded_at' => now()]);
                }
            }

            // Безусловная запись (не «если status !== downloaded», D136): читать `status` до
            // записи не нужно — на первом прогоне `provision()` уже создаёт документ со статусом
            // `downloaded`, запись тем же значением — идемпотентный no-op.
            $this->documents->update($document, ['status' => DocumentStatus::Downloaded]);

            PrepareDocumentJob::dispatch(
                documentId: $document->id,
                mode: RunMode::Manual,
                initiatedBy: $initiatedBy,
            )->afterCommit();
        });
    }
}
