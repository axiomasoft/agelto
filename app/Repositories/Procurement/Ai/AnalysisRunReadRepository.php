<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Models\Procurement\Ai\AnalysisRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Current execution and financial facts are derived; this reader cannot advance a run. */
final readonly class AnalysisRunReadRepository
{
    private const string WORK_LINK = "(w.stage='screening' AND w.document_version_id=prc_analysis_runs.document_version_id
        AND w.input_revision=prc_analysis_runs.expected_analysis_generation
        AND w.input_payload->>'screening_recipe_hash'=encode(prc_analysis_runs.recipe_hash,'hex')
        AND w.input_payload->>'representation_hash'=encode(prc_analysis_runs.representation_hash,'hex'))
        OR w.analysis_run_id=prc_analysis_runs.id
        OR EXISTS (SELECT 1 FROM prc_analysis_windows a WHERE a.id=w.analysis_window_id AND a.run_id=prc_analysis_runs.id)";

    /** @return Builder<AnalysisRun> */
    public function query(): Builder
    {
        return $this->decorate(AnalysisRun::query());
    }

    /**
     * @param  Builder<AnalysisRun>  $query
     * @return Builder<AnalysisRun>
     */
    public function decorate(Builder $query): Builder
    {
        $work = DB::table('prc_process_work as w')->whereRaw('('.self::WORK_LINK.')')
            ->selectRaw("min(w.created_at) AS started_at,max(w.updated_at) AS last_updated_at,
                count(*) FILTER (WHERE w.state IN ('ready','admitted','running','deferred','blocked')) AS active_count,
                bool_or(w.state='failed_item') AS failed,bool_or(w.state='cancelled') AS cancelled,
                bool_or(w.state='superseded') AS superseded,bool_or(w.state='blocked') AS blocked,
                bool_or(w.state='deferred') AS deferred,bool_or(w.state='running') AS running,
                string_agg(DISTINCT w.reason_code,', ' ORDER BY w.reason_code) AS reasons");
        $usage = DB::table('prc_analysis_llm_requests as m')->join('llm_provider_calls as c', 'c.physical_call_id', '=', 'm.physical_call_id')
            ->join('llm_budget_reservations as b', 'b.id', '=', 'c.reservation_id')
            ->join('llm_budget_periods as p', 'p.id', '=', 'b.period_id')
            ->whereColumn('m.analysis_run_id', 'prc_analysis_runs.id')
            ->selectRaw("count(*) AS calls,count(*) FILTER (WHERE c.cost_actual IS NULL OR c.usage_status='unknown') AS unknown_calls,
                CASE WHEN count(*)=0 OR bool_or(c.cost_actual IS NULL) OR count(DISTINCT p.currency)<>1 THEN NULL
                    ELSE round(sum(c.cost_actual)*1000000)::bigint END AS cost_micros,
                CASE WHEN count(DISTINCT p.currency)=1 THEN min(p.currency) END AS cost_currency,
                CASE WHEN count(DISTINCT p.currency)>1 THEN NULL ELSE
                    ceil(coalesce(sum(b.upper_bound) FILTER (WHERE b.state='reserved'),0)*1000000)::bigint END AS reserved_micros,
                CASE WHEN count(*)=0 OR bool_or(c.usage_status='unknown' OR c.usage IS NULL) THEN NULL ELSE sum((c.usage->>'uncached_input')::bigint) END AS prompt_tokens,
                CASE WHEN count(*)=0 OR bool_or(c.usage_status='unknown' OR c.usage IS NULL) THEN NULL ELSE sum((c.usage->>'cached_input')::bigint) END AS cached_tokens,
                CASE WHEN count(*)=0 OR bool_or(c.usage_status='unknown' OR c.usage IS NULL) THEN NULL ELSE sum((c.usage->>'output')::bigint) END AS completion_tokens,
                CASE WHEN count(*)=0 OR bool_or(c.usage_status='unknown' OR c.usage IS NULL) THEN NULL ELSE
                    sum((c.usage->>'uncached_input')::bigint+(c.usage->>'cached_input')::bigint+(c.usage->>'output')::bigint+(c.usage->>'thinking')::bigint) END AS total_tokens");

        return $query->leftJoinLateral($work, 'execution')->leftJoinLateral($usage, 'usage')
            ->select('prc_analysis_runs.*', 'execution.started_at', 'execution.reasons', 'usage.calls', 'usage.unknown_calls',
                'usage.cost_micros', 'usage.cost_currency', 'usage.reserved_micros', 'usage.prompt_tokens', 'usage.cached_tokens', 'usage.completion_tokens', 'usage.total_tokens')
            ->selectRaw("CASE WHEN prc_analysis_runs.publication_status='published' OR prc_analysis_runs.screening_disposition IN ('ignore','retain','review') THEN 'succeeded'
                WHEN NOT EXISTS (SELECT 1 FROM prc_document_versions v JOIN prc_documents d ON d.id=v.document_id
                    WHERE v.id=prc_analysis_runs.document_version_id AND v.current_analysis_run_id=prc_analysis_runs.id AND d.current_version_id=v.id) THEN 'superseded'
                WHEN execution.failed THEN 'failed_item' WHEN execution.cancelled THEN 'cancelled' WHEN execution.superseded THEN 'superseded'
                WHEN execution.blocked THEN 'blocked' WHEN execution.deferred THEN 'deferred' WHEN execution.running THEN 'running'
                WHEN execution.active_count=0 AND execution.started_at IS NOT NULL THEN 'succeeded' ELSE 'ready' END AS execution_state")
            ->selectRaw('CASE WHEN execution.active_count=0 THEN execution.last_updated_at END AS finished_at');
    }

    public function findActive(int $versionId): ?AnalysisRun
    {
        return $this->query()->where('prc_analysis_runs.document_version_id', $versionId)->where('execution.active_count', '>', 0)
            ->whereExists(static fn (\Illuminate\Database\Query\Builder $query) => $query->selectRaw('1')->from('prc_document_versions as v')
                ->whereColumn('v.current_analysis_run_id', 'prc_analysis_runs.id'))
            ->first();
    }

    public function hasActiveForDocument(int $documentId): bool
    {
        return $this->query()->where('execution.active_count', '>', 0)
            ->whereExists(static fn (\Illuminate\Database\Query\Builder $query) => $query->selectRaw('1')->from('prc_document_versions as v')
                ->join('prc_documents as d', 'd.current_version_id', '=', 'v.id')->where('d.id', $documentId)
                ->whereColumn('v.current_analysis_run_id', 'prc_analysis_runs.id'))->exists();
    }
}
