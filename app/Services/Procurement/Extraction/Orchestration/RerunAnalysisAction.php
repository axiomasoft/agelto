<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Orchestration;

use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Execution\ReanalysisSource;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Execution\WorkIdentity;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\SubjectKey;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunKind;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\SubjectKind;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Enums\Procurement\Process\Governance\IntentState;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Process\Projection\RunStoreRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Str;

/** Explicit new analysis intent; accepted results and financial history are never reset. */
final readonly class RerunAnalysisAction
{
    public function __construct(
        private AnalysisProviderConnections $connections,
        private AnalysisRecipeFactory $recipes,
        private AnalysisRepository $analyses,
        private WorkStoreRepository $works,
        private RunStoreRepository $runs,
        private ProcessTransaction $transactions,
    ) {}

    public function run(Analysis $analysis, ?ProcessUuid $commandId = null, ?int $actorId = null): WorkId
    {
        $previousRunId = new ProcessUuid(
            $analysis->analysis_run_id ?? throw new \InvalidArgumentException('An accepted canonical run is required.'),
        );
        $previousRun = $this->analyses->getRun($previousRunId);
        $commandId ??= new ProcessUuid((string) Str::uuid());
        $runId = ProcessUuid::derived('analysis-command:'.$commandId->value);
        $workId = new WorkId(ProcessUuid::derived('analysis-command-work:'.$commandId->value));
        $recipe = $this->recipes->make(
            preparation: $previousRun->recipe->preparationHash,
            context: $this->connections->preferred()->context,
            coverage: $previousRun->recipe->coverage,
        );
        $input = new ScreeningInput(
            documentVersionId: $previousRun->documentVersionId,
            representationHash: $previousRun->representationHash,
            screeningRecipeHash: $recipe->hash,
        );

        return $this->transactions->run(function () use ($previousRun, $commandId, $runId, $workId, $recipe, $input, $actorId): WorkId {
            $source = $this->analyses->rerunSourceLocked(previousRun: $previousRun, commandRunId: $runId);
            if ($source === null) {
                return $workId;
            }
            $run = new AnalysisRunSnapshot(
                id: $runId,
                documentVersionId: $previousRun->documentVersionId,
                representationId: $previousRun->representationId,
                representationHash: $previousRun->representationHash,
                recipe: $recipe,
                generation: 1,
                expectedDocumentHeadGeneration: $source->documentHeadGeneration,
                expectedAnalysisGeneration: $source->nextAnalysisGeneration,
                requestedReason: 'manual_reanalysis:'.$commandId->value,
                supersedesRunId: $previousRun->id,
            );
            $this->analyses->storeRunLocked($run);

            return $this->publishScreeningWork(
                run: $run,
                source: $source,
                input: $input,
                commandId: $commandId,
                actorId: $actorId,
            );
        });
    }

    /** Publish the new manual intent/work and close its process run in the caller's transaction. */
    private function publishScreeningWork(
        AnalysisRunSnapshot $run,
        ReanalysisSource $source,
        ScreeningInput $input,
        ProcessUuid $commandId,
        ?int $actorId,
    ): WorkId {
        $intentId = ProcessUuid::derived('analysis-command-intent:'.$commandId->value);
        $workId = new WorkId(ProcessUuid::derived('analysis-command-work:'.$commandId->value));
        $identity = new WorkIdentity(
            input: $input,
            manualRetryIntentId: $intentId,
            retryOfWorkId: $source->previousScreeningWorkId,
        );
        $subject = new SubjectKey(kind: SubjectKind::DocumentVersion, id: $run->documentVersionId);
        $this->works->storeIntent(
            id: $intentId,
            intentKey: $identity->key(),
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: $run->recipe->hash,
            accessPolicyHash: $run->representationHash,
            cycleSeq: $run->expectedAnalysisGeneration,
            expectedHeadGeneration: $run->expectedDocumentHeadGeneration,
            state: IntentState::Active,
        );
        $this->runs->storeRun(
            id: $run->id,
            kind: RunKind::Manual,
            triggerKey: 'analysis-command:'.$commandId->value,
            profileId: null,
            configuration: new VersionedPayload(json: '{"pipeline":"analysis","manual_reanalysis":true}', schemaVersion: 1),
            requestedBy: $actorId,
            eventId: ProcessUuid::derived('analysis-command-event:'.$commandId->value),
            projectionMessageId: ProcessUuid::derived('analysis-command-project:'.$commandId->value),
        );
        $this->works->storeWork(new StoreWorkData(
            id: $workId,
            input: $input,
            inputRevision: $run->expectedAnalysisGeneration,
            intentId: $intentId,
            cohortKey: $source->cohortKey,
            platformId: $source->platformId,
            originRunId: $run->id,
            priority: 100,
            eventId: ProcessUuid::derived('analysis-command-work-event:'.$commandId->value),
            projectionMessageId: ProcessUuid::derived('analysis-command-work-project:'.$commandId->value),
            identity: $identity,
        ));
        $this->works->linkRun(
            runId: $run->id,
            workId: $workId,
            relation: RunWorkRelation::Created,
            role: AccountingRole::Required,
        );
        $this->runs->completeCreatedRunLocked(
            runId: $run->id,
            eventId: ProcessUuid::derived('analysis-command-close:'.$commandId->value),
            projectionMessageId: ProcessUuid::derived('analysis-command-close-project:'.$commandId->value),
            actor: ActorKind::Operator,
            actorId: $actorId,
        );

        return $workId;
    }
}
