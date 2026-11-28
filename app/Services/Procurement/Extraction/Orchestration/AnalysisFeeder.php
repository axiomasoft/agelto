<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Orchestration;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Execution\PreparedAnalysisCandidate;
use App\Dto\Procurement\Ai\Planning\CoverageStrategy;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Execution\WorkIdentity;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\SubjectKey;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunKind;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\SubjectKind;
use App\Enums\Procurement\Process\Governance\IntentState;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Process\Projection\RunStoreRepository;
use App\Repositories\Procurement\Process\Work\IntentRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;

/** Independent prepared backlog. No acquisition/source-health predicate, admission or provider call. */
final readonly class AnalysisFeeder implements ReconcileCategory
{
    public function __construct(
        private AnalysisProviderConnections $connections,
        private AnalysisRecipeFactory $recipes,
        private AnalysisRepository $analyses,
        private IntentRepository $intents,
        private WorkStoreRepository $works,
        private RunStoreRepository $runs,
        private ProcessTransaction $transactions,
    ) {}

    public function name(): string
    {
        return 'analysis_planning';
    }

    public function reconcile(): int
    {
        try {
            $connection = $this->connections->preferred();
        } catch (OperationDenied) {
            return 0; // Configuration is required before creating a recipe.
        }
        $created = 0;
        foreach ($this->analyses->preparedBacklog() as $candidate) {
            $run = $this->planRun(candidate: $candidate, context: $connection->context);
            $created += $this->activate(candidate: $candidate, run: $run);
        }

        return $created;
    }

    private function planRun(PreparedAnalysisCandidate $candidate, PinnedLlmContext $context): AnalysisRunSnapshot
    {
        $recipe = $this->recipes->make(
            preparation: $candidate->preparationHash,
            context: $context,
            coverage: new CoverageStrategy(windowSpan: 8, overlap: 1),
        );

        return new AnalysisRunSnapshot(
            id: ProcessUuid::derived('analysis:'.$candidate->documentVersionId.':'.$recipe->hash->hex.':'.$candidate->representationHash->hex),
            documentVersionId: $candidate->documentVersionId,
            representationId: $candidate->representationId,
            representationHash: $candidate->representationHash,
            recipe: $recipe,
            generation: 1,
            expectedDocumentHeadGeneration: $candidate->documentHeadGeneration,
            expectedAnalysisGeneration: $candidate->analysisGeneration + 1,
            requestedReason: 'prepared_backlog',
        );
    }

    private function activate(PreparedAnalysisCandidate $candidate, AnalysisRunSnapshot $run): int
    {
        $input = new ScreeningInput(
            documentVersionId: $run->documentVersionId,
            representationHash: $run->representationHash,
            screeningRecipeHash: $run->recipe->hash,
        );
        $key = (new WorkIdentity($input))->key();

        return $this->transactions->run(function () use ($candidate, $run, $input, $key): int {
            if (! $this->analyses->startPreparedRunLocked(documentId: $candidate->documentId, run: $run)) {
                return 0;
            }
            $this->publishScreeningWork(candidate: $candidate, run: $run, input: $input, key: $key);

            return 1;
        });
    }

    /** The analysis run, intent, work, journal and discovery closure commit together. */
    private function publishScreeningWork(
        PreparedAnalysisCandidate $candidate,
        AnalysisRunSnapshot $run,
        ScreeningInput $input,
        ContentHash $key,
    ): void {
        $subject = new SubjectKey(kind: SubjectKind::DocumentVersion, id: $run->documentVersionId);
        // Staging intents never acquired a handler or performed a provider effect.
        $this->intents->retirePendingLocked(stage: StageCode::Screening, subject: $subject);
        $intentId = ProcessUuid::derived('analysis-intent:'.$key->hex);
        $this->works->storeIntent(
            id: $intentId,
            intentKey: $key,
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: $run->recipe->hash,
            accessPolicyHash: $run->representationHash,
            cycleSeq: 1,
            expectedHeadGeneration: $run->expectedDocumentHeadGeneration,
            state: IntentState::Active,
        );

        $processRunId = ProcessUuid::derived('analysis-process:'.$key->hex);
        $this->runs->storeRun(
            id: $processRunId,
            kind: RunKind::Profile,
            triggerKey: 'analysis:'.$key->hex,
            profileId: null,
            configuration: new VersionedPayload(
                json: CanonicalJson::encode(['pipeline' => 'analysis', 'recipe_hash' => $run->recipe->hash->hex]),
                schemaVersion: 1,
            ),
            requestedBy: null,
            eventId: ProcessUuid::derived('analysis-run-event:'.$key->hex),
            projectionMessageId: ProcessUuid::derived('analysis-run-project:'.$key->hex),
        );

        $workId = new WorkId(ProcessUuid::derived('analysis-work:'.$key->hex));
        $this->works->storeWork(new StoreWorkData(
            id: $workId,
            input: $input,
            inputRevision: $run->expectedAnalysisGeneration,
            intentId: $intentId,
            cohortKey: $candidate->procurementId === null ? 'manual' : 'platform:'.$candidate->platformId,
            platformId: $candidate->platformId,
            originRunId: $processRunId,
            priority: 100,
            eventId: ProcessUuid::derived('analysis-work-event:'.$key->hex),
            projectionMessageId: ProcessUuid::derived('analysis-work-project:'.$key->hex),
        ));
        $this->works->linkRun(
            runId: $processRunId,
            workId: $workId,
            relation: RunWorkRelation::Created,
            role: AccountingRole::Required,
        );
        $this->runs->completeCreatedRunLocked(
            runId: $processRunId,
            eventId: ProcessUuid::derived('analysis-close:'.$key->hex),
            projectionMessageId: ProcessUuid::derived('analysis-close-project:'.$key->hex),
        );
    }
}
