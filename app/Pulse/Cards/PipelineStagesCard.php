<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse-карточка стадий за последние 24 часа, без дублирования фактов в Pulse tables.
 * `screening` и `extraction` считают terminal work transitions `succeeded`/`failed_item`.
 * `prepare` показывает приближение по документам: успех — документ вышел из discovered/downloaded
 * и обновлён в окне; ошибка — `failed_permanently=true` с обновлением в том же окне.
 * Эти document gauges не являются точной историей переходов prepare.
 */
#[Lazy]
final class PipelineStagesCard extends Card
{
    public function render(): View
    {
        $since = CarbonImmutable::now()->subDay();

        return view('livewire.pulse.pipeline-stages', [
            'stages' => collect([
                $this->prepareRow($since),
                $this->analysisRow(step: AnalysisStep::Screening, label: 'screening', since: $since),
                $this->analysisRow(step: AnalysisStep::Extraction, label: 'extraction', since: $since),
            ]),
        ]);
    }

    /**
     * @return array{stage: string, succeeded: int, failed: int, failure_rate: float}
     */
    private function prepareRow(CarbonImmutable $since): array
    {
        $succeeded = Document::query()
            ->whereNotIn('status', [DocumentStatus::Discovered->value, DocumentStatus::Downloaded->value])
            ->where('updated_at', '>=', $since)
            ->count();

        $failed = Document::query()
            ->where('failed_permanently', true)
            ->where('updated_at', '>=', $since)
            ->count();

        return $this->row(stage: 'prepare', succeeded: $succeeded, failed: $failed);
    }

    /**
     * @return array{stage: string, succeeded: int, failed: int, failure_rate: float}
     */
    private function analysisRow(AnalysisStep $step, string $label, CarbonImmutable $since): array
    {
        /** @var Collection<string, int> $totals */
        $totals = DB::table('prc_process_transitions as t')
            ->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')->where('w.stage', $step->value)
            ->where('t.occurred_at', '>=', $since)
            ->whereIn('t.new_state', ['succeeded', 'failed_item'])
            ->selectRaw('t.new_state, count(*) as total')->groupBy('t.new_state')
            ->pluck('total', 'new_state');

        return $this->row(
            stage: $label,
            succeeded: (int) ($totals['succeeded'] ?? 0),
            failed: (int) ($totals['failed_item'] ?? 0),
        );
    }

    /**
     * @return array{stage: string, succeeded: int, failed: int, failure_rate: float}
     */
    private function row(string $stage, int $succeeded, int $failed): array
    {
        $total = $succeeded + $failed;

        return [
            'stage' => $stage,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'failure_rate' => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
        ];
    }
}
