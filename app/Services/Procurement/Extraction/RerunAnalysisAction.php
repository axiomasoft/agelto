<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\RunKind;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit new analysis intent; accepted results and financial history are never reset. */
final readonly class RerunAnalysisAction
{
    public function __construct(private AnalysisProviderConnections $connections, private AnalysisRecipeFactory $recipes,
        private AnalysisRepository $analyses, private WorkStoreRepository $works, private RunStoreRepository $runs,
        private TransitionRepository $transitions, private ProcessTransaction $transactions) {}

    public function run(Analysis $analysis, ?ProcessUuid $commandId = null, ?int $actorId = null): WorkId
    {
        $oldId = new ProcessUuid($analysis->analysis_run_id ?? throw new \InvalidArgumentException('An accepted canonical run is required.'));
        $old = $this->analyses->getRun($oldId);
        $commandId ??= new ProcessUuid((string) Str::uuid());
        $id = ListingStoreRepository::id('analysis-command:'.$commandId->value);
        $work = new WorkId(ListingStoreRepository::id('analysis-command-work:'.$commandId->value));
        $intent = ListingStoreRepository::id('analysis-command-intent:'.$commandId->value);
        $recipe = $this->recipes->make($old->recipe->preparationHash, $this->connections->preferred()->context,
            $old->recipe->coverage);
        $input = new ScreeningInput($old->documentVersionId, $old->representationHash, $recipe->hash);

        return $this->transactions->run(function () use ($old, $commandId, $id, $work, $intent, $recipe, $input, $actorId): WorkId {
            // Same sorted subject tier as finalization: analysis -> version -> document -> work.
            LedgerRow::one('SELECT id FROM prc_analysis_runs WHERE id=? FOR UPDATE', [$old->id->value]);
            $version = LedgerRow::one('SELECT document_id,analysis_generation,current_analysis_run_id FROM prc_document_versions WHERE id=? FOR UPDATE', [$old->documentVersionId])
                ?? throw new \InvalidArgumentException('Missing source version.');
            $head = LedgerRow::one('SELECT current_version_id,head_generation,platform_id,procurement_id FROM prc_documents WHERE id=? FOR UPDATE', [$version->int('document_id')])
                ?? throw new \InvalidArgumentException('Missing source document.');
            $previous = LedgerRow::one('SELECT document_version_id,supersedes_run_id FROM prc_analysis_runs WHERE id=?', [$id->value]);
            if ($previous !== null) {
                if ($previous->int('document_version_id') !== $old->documentVersionId || $previous->nullableString('supersedes_run_id') !== $old->id->value) {
                    throw new \InvalidArgumentException('Reanalysis command identity conflict.');
                }

                return $work;
            }
            if ($version->nullableString('current_analysis_run_id') !== $old->id->value
                || $head->nullableInt('current_version_id') !== $old->documentVersionId
                || $head->int('head_generation') !== $old->expectedDocumentHeadGeneration
                || LedgerRow::one("SELECT m.id FROM prc_analysis_llm_requests m JOIN llm_provider_calls c ON c.physical_call_id=m.physical_call_id
                    JOIN llm_provider_requests q ON q.logical_request_id=c.logical_request_id
                    WHERE m.analysis_run_id=? AND (q.state IN ('sending','unknown') OR c.outcome='unknown') LIMIT 1", [$old->id->value]) !== null) {
                throw new \InvalidArgumentException('Reanalysis requires the current source and resolved provider history.');
            }
            $prior = LedgerRow::one("SELECT w.id FROM prc_process_work w WHERE w.stage='screening' AND w.document_version_id=?
                AND w.input_revision=?", [$old->documentVersionId, $old->expectedAnalysisGeneration])
                ?? throw new \InvalidArgumentException('Missing original screening work.');
            if (LedgerRow::one("SELECT w.id FROM prc_process_work w WHERE w.state NOT IN ('succeeded','failed_item','cancelled','superseded')
                AND (w.id=? OR w.analysis_run_id=? OR w.analysis_window_id IN (SELECT id FROM prc_analysis_windows WHERE run_id=?)) LIMIT 1",
                [$prior->string('id'), $old->id->value, $old->id->value]) !== null) {
                throw new \InvalidArgumentException('Finish or explicitly cancel the current analysis work first.');
            }
            $run = new AnalysisRunSnapshot($id, $old->documentVersionId, $old->representationId, $old->representationHash,
                $recipe, 1, $head->int('head_generation'), $version->int('analysis_generation') + 1,
                'manual_reanalysis:'.$commandId->value, $old->id);
            $this->analyses->storeRunLocked($run);
            $identity = new WorkIdentity($input, $intent, new WorkId(new ProcessUuid($prior->string('id'))));
            $this->works->storeIntent($intent, $identity->key(), StageCode::Screening,
                new SubjectKey(SubjectKind::DocumentVersion, $run->documentVersionId), $recipe->hash, $run->representationHash,
                $run->expectedAnalysisGeneration, $run->expectedDocumentHeadGeneration, IntentState::Active);
            $this->runs->storeRun($id, RunKind::Manual, 'analysis-command:'.$commandId->value, null,
                new VersionedPayload('{"pipeline":"analysis","manual_reanalysis":true}', 1), $actorId,
                ListingStoreRepository::id('analysis-command-event:'.$commandId->value), ListingStoreRepository::id('analysis-command-project:'.$commandId->value));
            $platform = $head->int('platform_id');
            $this->works->storeWork($work, $input, $run->expectedAnalysisGeneration, $intent,
                $head->nullableInt('procurement_id') === null ? 'manual' : 'platform:'.$platform, $platform, $id, 100,
                ListingStoreRepository::id('analysis-command-work-event:'.$commandId->value), ListingStoreRepository::id('analysis-command-work-project:'.$commandId->value), $identity);
            $this->works->linkRun($id, $work, RunWorkRelation::Created, AccountingRole::Required);
            DB::update("UPDATE prc_process_runs SET discovery_outcome='complete',discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?", [$id->value]);
            $this->transitions->recordRun(ListingStoreRepository::id('analysis-command-close:'.$commandId->value), ListingStoreRepository::id('analysis-command-close-project:'.$commandId->value),
                $id, 2, 'collecting', 'complete', null, ActorKind::Operator);

            return $work;
        });
    }
}
