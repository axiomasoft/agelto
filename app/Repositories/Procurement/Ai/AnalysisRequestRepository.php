<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Procurement\Ai\Execution\AnalysisRequestLink;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;

/** Explicit active linkage, never ORDER BY latest call. Historical physical rows remain immutable. */
final class AnalysisRequestRepository
{
    public function __construct(private readonly ProcessPayloadCodec $codec) {}

    public function linkLocked(ExecutionLease $lease, ProcessUuid $run, AuthorizedProviderOperation $operation, ProcessUuid $linkId): void
    {
        CallerTransaction::require('Analysis request linking');
        $inserted = DB::affectingStatement("INSERT INTO prc_analysis_llm_requests(id,analysis_run_id,work_id,attempt_id,logical_request_id,physical_call_id,expected_input_hash,recipe_hash)
            SELECT ?,r.id,w.id,a.id,c.logical_request_id,c.physical_call_id,w.input_hash,r.recipe_hash
            FROM prc_analysis_runs r JOIN prc_document_versions v ON v.id=r.document_version_id
            JOIN prc_documents d ON d.id=v.document_id JOIN prc_process_work w ON
                (w.document_version_id=r.document_version_id OR w.analysis_run_id=r.id OR
                    w.analysis_window_id IN (SELECT id FROM prc_analysis_windows WHERE run_id=r.id))
            JOIN prc_process_attempts a ON a.work_id=w.id JOIN llm_provider_calls c ON c.owner_attempt_id=a.id
            JOIN llm_provider_requests q ON q.logical_request_id=c.logical_request_id
            WHERE r.id=? AND w.id=? AND a.id=? AND a.generation=? AND c.physical_call_id=? AND c.owner_work_generation=a.generation
              AND w.input_hash=decode(?,'hex') AND q.recipe_hash=r.recipe_hash
              AND d.current_version_id=v.id AND d.head_generation=r.expected_document_head_generation
              AND v.current_analysis_run_id=r.id AND v.analysis_generation=r.expected_analysis_generation
            ON CONFLICT(physical_call_id) DO NOTHING", [
            $linkId->value, $run->value, $lease->workId->value->value, $lease->attemptId->value, $lease->generation, $operation->physicalCallId, $lease->inputHash->hex,
        ]);
        $mapping = $this->active($lease->workId);
        if (($inserted !== 1 && $mapping?->physicalCallId !== $operation->physicalCallId) || $mapping === null) {
            throw new \LogicException('Exact analysis/work/attempt/request linkage mismatch.');
        }
    }

    public function active(WorkId $work): ?AnalysisRequestLink
    {
        $row = LedgerRow::one("SELECT *,encode(expected_input_hash,'hex') AS input_hex,encode(recipe_hash,'hex') AS recipe_hex
            FROM prc_analysis_llm_requests WHERE work_id=? AND recovery_active", [$work->value->value]);

        return $row === null ? null : $this->link($row);
    }

    public function physical(string $physical): AnalysisRequestLink
    {
        $row = LedgerRow::one("SELECT *,encode(expected_input_hash,'hex') AS input_hex,encode(recipe_hash,'hex') AS recipe_hex
            FROM prc_analysis_llm_requests WHERE physical_call_id=?", [$physical]) ?? throw new \LogicException('Missing exact analysis provider mapping.');

        return $this->link($row);
    }

    /** Missing physical identity is an invariant failure; a different immutable input is not a match. */
    public function physicalForInput(string $physical, AnalysisRunSnapshot $run, WorkInput $input): ?AnalysisRequestLink
    {
        $link = $this->physical($physical);
        $inputHash = WorkStoreRepository::inputHash($this->codec->encodeInput($input)->json ?? '');
        if ($link->analysisRunId != $run->id || $link->expectedWorkInputHash != $inputHash || $link->recipeHash != $run->recipe->hash) {
            return null;
        }

        return $link;
    }

    /** Rechecked while the joint owner holds document/version subjects; no latest-call heuristic. */
    public function current(string $physical): bool
    {
        return LedgerRow::one('SELECT 1 AS current FROM prc_analysis_llm_requests m
            JOIN prc_analysis_runs r ON r.id=m.analysis_run_id JOIN prc_document_versions v ON v.id=r.document_version_id
            JOIN prc_documents d ON d.id=v.document_id
            WHERE m.physical_call_id=? AND m.recovery_active AND m.recipe_hash=r.recipe_hash
              AND d.current_version_id=v.id AND d.head_generation=r.expected_document_head_generation
              AND v.current_analysis_run_id=r.id AND v.analysis_generation=r.expected_analysis_generation', [$physical]) !== null;
    }

    /** Caller has committed the associated chunk/progress or explicit next workflow step. */
    public function consumeLocked(WorkId $work, string $physical): void
    {
        CallerTransaction::require('Analysis provider recovery consumption');
        if (DB::update('UPDATE prc_analysis_llm_requests SET recovery_active=false WHERE work_id=? AND physical_call_id=? AND recovery_active', [$work->value->value, $physical]) !== 1) {
            throw new \LogicException('Provider directive consumption conflict.');
        }
    }

    private function link(LedgerRow $row): AnalysisRequestLink
    {
        return new AnalysisRequestLink(
            analysisRunId: new ProcessUuid($row->string('analysis_run_id')),
            workId: new WorkId(new ProcessUuid($row->string('work_id'))),
            logicalRequestId: $row->string('logical_request_id'),
            physicalCallId: $row->string('physical_call_id'),
            expectedWorkInputHash: new ContentHash($row->string('input_hex')),
            recipeHash: new ContentHash($row->string('recipe_hex')),
        );
    }
}
