<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Кастомная Pulse-карточка «Стадии за 24ч» (D25, P7.5 Intent п.2, phases/P7.md): счётчики
 * завершений/неуспехов по стадиям пайплайна за фиксированное окно 24ч — прямой SQL-агрегат по
 * `prc_documents`/`prc_ai_analyses` (Code Guidance: карточки строятся SQL-агрегатами, данные НЕ
 * дублируются в pulse-таблицы через `Pulse::record()`).
 *
 * `prepare` не имеет собственного статус-поля анализа (ТЗ 03: документ либо остаётся в
 * `downloaded` при провале подготовки, либо переходит дальше по `prc_documents.status`) —
 * best-effort приближение (тот же класс допущений, что `EstimateValidator`/`ScreeningProcessor`
 * footer-эвристики, P6.2/P6.3): succeeded = документы, покинувшие `discovered`/`downloaded`
 * (реально прошедшие prepare, не только обнаруженные краулингом) за окно, failed = документы с
 * `failed_permanently=true`, обновлённые за окно. `screening`/`extraction` читаются точно из
 * `prc_ai_analyses.status` по шагу (`AnalysisStep`).
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
        $totals = Analysis::query()
            ->where('step', $step->value)
            ->where('updated_at', '>=', $since)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->pluck('total', 'status');

        return $this->row(
            stage: $label,
            succeeded: (int) ($totals[AnalysisStatus::Succeeded->value] ?? 0),
            failed: (int) ($totals[AnalysisStatus::Failed->value] ?? 0),
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
