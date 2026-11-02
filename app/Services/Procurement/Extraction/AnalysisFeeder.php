<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\CoverageStrategy;
use App\Dto\Procurement\Process\ContentHash;
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
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Independent prepared backlog. No acquisition/source-health predicate, admission or provider call. */
final readonly class AnalysisFeeder implements ReconcileCategory
{
    public function __construct(private AnalysisProviderConnections $connections, private AnalysisRecipeFactory $recipes,
        private AnalysisRepository $analyses, private WorkStoreRepository $works,
        private RunStoreRepository $runs, private TransitionRepository $transitions, private ProcessTransaction $transactions) {}

    public function name(): string
    {
        return 'analysis_planning';
    }

    public function reconcile(): int
    {
        try {
            $connection = $this->connections->preferred();
        } catch (OperationDenied) {
            return 0; // No account/model is guessed. Configuration is required before creating a recipe.
        }
        $created = 0;
        $rows = LedgerRow::all("SELECT d.id AS document_id,d.head_generation,d.platform_id,d.procurement_id,v.id AS version_id,v.analysis_generation,
            r.id AS representation_id,encode(r.content_hash,'hex') AS hash,encode(r.preparation_recipe_hash,'hex') AS preparation_hash
            FROM prc_documents d JOIN prc_document_versions v ON v.id=d.current_version_id
            JOIN prc_document_representations r ON r.document_version_id=v.id
            WHERE d.removed_at IS NULL AND v.current_analysis_run_id IS NULL AND r.range_key='complete' AND r.kind='text'
              AND r.id=(SELECT max(s.id) FROM prc_document_representations s WHERE s.document_version_id=v.id AND s.range_key='complete' AND s.kind='text')
            ORDER BY v.id LIMIT 50");
        foreach ($rows as $row) {
            $recipe = $this->recipes->make(new ContentHash($row->string('preparation_hash')), $connection->context, new CoverageStrategy(8, 1));
            $runId = ListingStoreRepository::id('analysis:'.$row->int('version_id').':'.$recipe->hash->hex.':'.$row->string('hash'));
            $candidate = new AnalysisRunSnapshot($runId, $row->int('version_id'), $row->int('representation_id'), new ContentHash($row->string('hash')),
                $recipe, 1, $row->int('head_generation'), $row->int('analysis_generation') + 1, 'prepared_backlog');
            $run = $candidate;
            $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $recipe->hash);
            $key = (new WorkIdentity($input))->key();
            $created += $this->transactions->run(function () use ($row, $run, $input, $key): int {
                // Same sorted subject tier used by claim/finalize. No work/capacity is held yet.
                $version = LedgerRow::one('SELECT document_id,analysis_generation,current_analysis_run_id FROM prc_document_versions WHERE id=? FOR UPDATE', [$run->documentVersionId]);
                $head = LedgerRow::one('SELECT current_version_id,head_generation FROM prc_documents WHERE id=? FOR UPDATE', [$row->int('document_id')]);
                if ($version === null || $head === null || $version->int('document_id') !== $row->int('document_id')
                    || $version->nullableString('current_analysis_run_id') !== null || $version->int('analysis_generation') + 1 !== $run->expectedAnalysisGeneration
                    || $head->nullableInt('current_version_id') !== $run->documentVersionId || $head->int('head_generation') !== $run->expectedDocumentHeadGeneration) {
                    return 0;
                }
                $this->analyses->storeRunLocked($run);
                // Staging intents never acquired a handler or performed a provider effect.
                DB::update("UPDATE prc_operation_intents SET state='terminal',pending_reason=NULL WHERE stage='screening'
                    AND subject_kind='prc_document_versions' AND subject_id=? AND state='pending'", [(string) $run->documentVersionId]);
                $intent = ListingStoreRepository::id('analysis-intent:'.$key->hex);
                $this->works->storeIntent($intent, $key, StageCode::Screening, new SubjectKey(SubjectKind::DocumentVersion, $run->documentVersionId),
                    $run->recipe->hash, $run->representationHash, 1, $run->expectedDocumentHeadGeneration, IntentState::Active);
                $processRun = ListingStoreRepository::id('analysis-process:'.$key->hex);
                $this->runs->storeRun($processRun, RunKind::Profile, 'analysis:'.$key->hex, null,
                    new VersionedPayload(CanonicalJson::encode(['pipeline' => 'analysis', 'recipe_hash' => $run->recipe->hash->hex]), 1), null,
                    ListingStoreRepository::id('analysis-run-event:'.$key->hex), ListingStoreRepository::id('analysis-run-project:'.$key->hex));
                $work = new WorkId(ListingStoreRepository::id('analysis-work:'.$key->hex));
                $platform = $row->nullableInt('platform_id');
                $this->works->storeWork($work, $input, $run->expectedAnalysisGeneration, $intent,
                    $row->nullableInt('procurement_id') === null ? 'manual' : 'platform:'.$platform, $platform, $processRun, 100,
                    ListingStoreRepository::id('analysis-work-event:'.$key->hex), ListingStoreRepository::id('analysis-work-project:'.$key->hex));
                $this->works->linkRun($processRun, $work, RunWorkRelation::Created, AccountingRole::Required);
                DB::update("UPDATE prc_process_runs SET discovery_outcome='complete',discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?", [$processRun->value]);
                $this->transitions->recordRun(ListingStoreRepository::id('analysis-close:'.$key->hex), ListingStoreRepository::id('analysis-close-project:'.$key->hex),
                    $processRun, 2, 'collecting', 'complete', null, ActorKind::System);

                return 1;
            });
        }

        return $created;
    }
}
