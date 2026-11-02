<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Dto\Procurement\Ai\ScreeningCompleted;
use App\Dto\Procurement\Ai\ScreeningProgress;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\AnalysisAssetRecovery;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Screening\ScreeningContract;
use App\Services\Procurement\Screening\ScreeningPolicy;
use App\Services\Procurement\Screening\ScreeningStageCodec;
use Illuminate\Support\Facades\DB;

/** Only WorkFinalizer/checkpoint invokes these writes, under document + version authority. */
final readonly class ScreeningFinalizer implements StageResultRepository
{
    public function __construct(private AnalysisRepository $analyses, private AnalysisRequestRepository $mapping,
        private RequestRepository $ledger, private ScreeningContract $contract, private ScreeningPolicy $policy,
        private AnalysisAssetRecovery $assetRecovery, private AnalysisPlanRepository $plans,
        private AnalysisResultProjectionRepository $projection) {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        CallerTransaction::require('Screening progress');
        if (! $input instanceof ScreeningInput || ! $progress instanceof ScreeningProgress) {
            throw new \InvalidArgumentException('Screening progress binding mismatch.');
        }
        $run = $this->analyses->forScreening($input);
        if ($progress->preparedSource !== null) {
            $this->analyses->sealSourceLocked($run, $progress->preparedSource);

            return;
        }
        if ($progress->recoveredAsset !== null) {
            $this->assetRecovery->storeLocked($input, $run, $progress->recoveredAsset);

            return;
        }
        if ($progress->discovery !== null) {
            $progress->discovery->assertPartition($run->source ?? throw new \LogicException('Discovery source missing.'));
        }
        if ($progress->result !== null) {
            $this->contract->restore(CanonicalJson::encode($progress->result->toArray()));
        }
        if ($progress->physicalCallId === null) {
            if ($progress->result !== null && $progress->discovery === null) {
                throw new \LogicException('New screening output requires receipt consumption.');
            }

            return; // Progress-only saves preserve the pinned provider directive in the core envelope.
        }
        $receipt = $progress->receipt ?? throw new \LogicException('Screening operation requires verified raw.');
        $physical = $progress->physicalCallId->value;
        $link = $this->mapping->physical($physical);
        $inputHash = WorkStoreRepository::inputHash((new ProcessPayloadCodec)->encodeInput($input)->json ?? '');
        if ($receipt->call->authorization->physicalCallId !== $physical || $receipt->call->recipeHash !== $run->recipe->hash->hex
            || $receipt->call->context != $run->recipe->llm || $receipt->call->inputHash !== ($progress->consumedRange === null ? $run->screeningInputHash()->hex : $run->discoveryInputHash($progress->consumedRange)->hex)
            || $receipt->call->purpose !== OperationPurpose::Generate || $receipt->assessment->outcome !== ReceiptOutcome::Success
            || $link->string('analysis_run_id') !== $run->id->value || $link->string('input_hex') !== $inputHash->hex
            || $link->string('recipe_hex') !== $run->recipe->hash->hex) {
            throw new \LogicException('Screening receipt/input identity mismatch.');
        }
        $this->ledger->acceptReceiptLocked($receipt);
        $this->mapping->consumeLocked(new WorkId(new ProcessUuid($link->string('work_id'))), $physical);
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Screening result publication');
        if (! $input instanceof ScreeningInput || ! $result instanceof ScreeningCompleted) {
            throw new \InvalidArgumentException('Screening result binding mismatch.');
        }
        $run = $this->analyses->forScreening($input);
        if ($result->runId != $run->id) {
            throw new \InvalidArgumentException('Screening result belongs to another run.');
        }
        $screening = $this->contract->restore(CanonicalJson::encode($result->result->toArray()));
        $canonical = CanonicalJson::encode($screening->toArray());
        $work = LedgerRow::one("SELECT checkpoint::text AS checkpoint,checkpoint_schema_version FROM prc_process_work
            WHERE id=? AND stage='screening' AND document_version_id=? AND state='running' AND input_hash=decode(?,'hex')", [
            $result->sourceWorkId->value->value, $input->documentVersionId,
            WorkStoreRepository::inputHash((new ProcessPayloadCodec)->encodeInput($input)->json ?? '')->hex]);
        $checkpoint = $work === null ? null : (new ProcessPayloadCodec([new ScreeningStageCodec]))->decodeCheckpoint(
            new VersionedPayload($work->nullableString('checkpoint'), $work->nullableInt('checkpoint_schema_version')));
        $accepted = $checkpoint?->stageProgress;
        if (! $accepted instanceof ScreeningProgress || $accepted->result === null || $accepted->blocked !== null
            || ! $checkpoint->providerRecoveryPlan instanceof NoProviderRecovery || $this->mapping->active($result->sourceWorkId) !== null
            || CanonicalJson::encode($accepted->result->toArray()) !== $canonical) {
            throw new \LogicException('Screening completion must reference its committed accepted progress.');
        }
        $decision = $this->policy->decide($screening, $run->recipe->screening);
        $successors = [];
        if ($decision->disposition === ScreeningDisposition::Extract) {
            if ($run->source !== null) {
                $this->plans->sealDiscoveryLocked($run, $accepted->discovery ?? throw new PublicationBlocked(BlockedReason::ExtractionIncomplete));
            }
            $windows = LedgerRow::all('SELECT window_key FROM prc_analysis_windows WHERE run_id=? ORDER BY window_key COLLATE "C" LIMIT 4097', [$run->id->value]);
            if ($windows === [] || count($windows) > 4096) {
                throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
            }
            foreach ($windows as $window) {
                $successors[] = new ExtractionWindowInput($run->id, $window->string('window_key'), $run->recipe->hash);
            }
        }
        $updated = DB::update('UPDATE prc_analysis_runs SET screening_result=?::jsonb,screening_result_schema_version=1,screening_disposition=?
            WHERE id=? AND screening_result IS NULL', [$canonical, $decision->disposition->value, $run->id->value]);
        if ($updated !== 1) {
            $old = LedgerRow::one('SELECT screening_result::text AS result,screening_disposition FROM prc_analysis_runs WHERE id=?', [$run->id->value]);
            if ($old === null || CanonicalJson::encode(json_decode($old->string('result'), flags: JSON_THROW_ON_ERROR)) !== $canonical
                || $old->string('screening_disposition') !== $decision->disposition->value) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }
        }

        $this->projection->screening($run, new StoredScreeningResult($screening->confidence, $screening->summary,
            $screening->estimate_cardinality, $screening->tags, $screening->classification_note, $run->source?->pageCount, $decision),
            $screening->document_type);

        return $successors;
    }
}
