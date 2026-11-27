<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Models\Llm\LlmCall;
use App\Repositories\Llm\LlmCallReadRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Exact immutable Procurement mapping, including cancelled and superseded financial history. */
final readonly class AnalysisCallReadRepository
{
    public function __construct(private LlmCallReadRepository $calls) {}

    /** @return Builder<LlmCall> */
    public function forRun(string $analysisRunId): Builder
    {
        new ProcessUuid($analysisRunId);

        return $this->calls->query()->whereIn('physical_call_id', DB::table('prc_analysis_llm_requests')
            ->where('analysis_run_id', $analysisRunId)->select('physical_call_id'))->orderBy('called_at')->orderBy('physical_call_id');
    }

    public function findInRun(string $callId, string $analysisRunId): ?LlmCall
    {
        new ProcessUuid($callId);

        return $this->forRun($analysisRunId)->where('physical_call_id', $callId)->first();
    }
}
