<?php

declare(strict_types=1);

use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Network\Observation;
use App\Models\Procurement\Process\Incident;
use App\Models\Procurement\Process\OperationIntent;
use App\Models\Procurement\Process\OutboxMessage;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\RunWork;
use App\Models\Procurement\Process\Transition;
use App\Models\Procurement\Process\WorkItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->indexes() as $name => $definition) {
            DB::statement("CREATE INDEX {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }

    /** @return array<string, string> */
    private function indexes(): array
    {
        $outbox = (new OutboxMessage)->getTable();
        $transitions = (new Transition)->getTable();
        $calls = (new LlmCall)->getTable();
        $analysisRuns = (new AnalysisRun)->getTable();
        $observations = (new Observation)->getTable();
        $work = (new WorkItem)->getTable();
        $runs = (new ProcessRun)->getTable();
        $incidents = (new Incident)->getTable();
        $runWork = (new RunWork)->getTable();
        $intents = (new OperationIntent)->getTable();

        // The other four tables are SQL-owned ledgers without Eloquent models.
        return [
            'prc_process_outbox_published_idx' => "{$outbox} (published_at, message_id) WHERE published_at IS NOT NULL",
            'prc_process_transitions_occurred_idx' => "{$transitions} (occurred_at, event_id)",
            'prc_process_transitions_work_state_time_idx' => "{$transitions} (entity_kind, new_state, occurred_at) WHERE entity_kind = 'work'",
            'llm_provider_calls_send_time_idx' => "{$calls} (coalesce(sent_at, send_claimed_at))",
            'prc_analysis_llm_requests_run_call_idx' => 'prc_analysis_llm_requests (analysis_run_id, physical_call_id)',
            'prc_analysis_runs_document_version_idx' => "{$analysisRuns} (document_version_id)",
            'prc_observations_attempt_idx' => "{$observations} (attempt_id)",
            'prc_observations_occurred_idx' => "{$observations} (occurred_at)",
            'prc_request_attempts_started_idx' => 'prc_request_attempts (started_at)',
            'prc_process_work_updated_idx' => "{$work} (updated_at DESC)",
            'prc_process_work_detail_latest_idx' => "{$work} (procurement_id, created_at DESC, id DESC) WHERE stage = 'detail' AND procurement_id IS NOT NULL",
            'prc_process_runs_requested_idx' => "{$runs} (requested_at DESC)",
            'prc_process_incidents_state_seen_idx' => "{$incidents} (state, last_seen_at DESC)",
            'prc_process_run_work_work_idx' => "{$runWork} (work_id)",
            'prc_operation_intents_state_due_idx' => "{$intents} (state, next_due_at)",
            'prc_status_refreshes_next_due_idx' => 'prc_status_refreshes (next_due_at)',
            'prc_detail_snapshots_published_idx' => "prc_detail_snapshots (procurement_id, created_at DESC) WHERE completeness = 'complete' AND part_key LIKE '%:published'",
        ];
    }
};
