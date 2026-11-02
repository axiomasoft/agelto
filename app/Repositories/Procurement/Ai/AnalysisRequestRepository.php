<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;

/** Explicit active linkage, never ORDER BY latest call. Historical physical rows remain immutable. */
final class AnalysisRequestRepository
{
    public function linkLocked(ExecutionLease $lease, ProcessUuid $run, AuthorizedProviderOperation $operation, ProcessUuid $linkId): void
    {
        CallerTransaction::require('Analysis request linking');
        $inserted = DB::affectingStatement("INSERT INTO prc_analysis_llm_requests(id,analysis_run_id,work_id,attempt_id,logical_request_id,physical_call_id,expected_input_hash,recipe_hash)
            SELECT ?,r.id,w.id,a.id,c.logical_request_id,c.physical_call_id,w.input_hash,r.recipe_hash
            FROM prc_analysis_runs r JOIN prc_document_versions v ON v.id=r.document_version_id
            JOIN prc_documents d ON d.id=v.document_id JOIN prc_process_work w ON w.document_version_id=r.document_version_id
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
        if (($inserted !== 1 && $mapping?->string('physical_call_id') !== $operation->physicalCallId) || $mapping === null) {
            throw new \LogicException('Exact analysis/work/attempt/request linkage mismatch.');
        }
    }

    public function active(WorkId $work): ?LedgerRow
    {
        return LedgerRow::one("SELECT *,encode(expected_input_hash,'hex') AS input_hex,encode(recipe_hash,'hex') AS recipe_hex
            FROM prc_analysis_llm_requests WHERE work_id=? AND recovery_active", [$work->value->value]);
    }

    public function physical(string $physical): LedgerRow
    {
        return LedgerRow::one("SELECT *,encode(expected_input_hash,'hex') AS input_hex,encode(recipe_hash,'hex') AS recipe_hex
            FROM prc_analysis_llm_requests WHERE physical_call_id=?", [$physical]) ?? throw new \LogicException('Missing exact analysis provider mapping.');
    }

    /** Caller has committed the associated chunk/progress or explicit next workflow step. */
    public function consumeLocked(WorkId $work, string $physical): void
    {
        CallerTransaction::require('Analysis provider recovery consumption');
        if (DB::update('UPDATE prc_analysis_llm_requests SET recovery_active=false WHERE work_id=? AND physical_call_id=? AND recovery_active', [$work->value->value, $physical]) !== 1) {
            throw new \LogicException('Provider directive consumption conflict.');
        }
    }
}
