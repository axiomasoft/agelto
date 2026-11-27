<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only снимок документов по стадиям и AI work по шагу и состоянию. Дополнительно
 * показывает причины unprocessable и borderline screening, ожидающий операторского решения.
 * Временные окна не применяются; возраст зависших работ проверяет `process:diagnostics`.
 */
#[Description('Показывает текущее состояние пайплайна документов и AI-анализа по всем стадиям')]
#[Signature('pipeline:status')]
final class PipelineStatus extends Command
{
    public function handle(): int
    {
        $this->info('Пайплайн EST-AI — состояние на '.now()->format('d.m.Y H:i:s'));
        $this->newLine();

        $this->printDocumentsStatus();
        $this->newLine();
        $this->printAnalysesStatus();
        $this->newLine();
        $this->printUnprocessableBreakdown();
        $this->newLine();
        $this->printBorderlineCount();

        return self::SUCCESS;
    }

    private function printDocumentsStatus(): void
    {
        $this->line('<fg=yellow>Документы (prc_documents)</>');

        $rows = Document::query()
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $labels = [
            DocumentStatus::Discovered->value => 'Discovered',
            DocumentStatus::Downloaded->value => 'Downloaded',
            DocumentStatus::Expanded->value => 'Expanded',
            DocumentStatus::Prepared->value => 'Prepared',
            DocumentStatus::Screened->value => 'Screened',
            DocumentStatus::Extracted->value => 'Extracted',
            DocumentStatus::Ignored->value => 'Ignored',
            DocumentStatus::Failed->value => 'Failed',
            DocumentStatus::Unprocessable->value => 'Unprocessable',
        ];

        $tableRows = [];
        foreach ($labels as $value => $label) {
            $tableRows[] = [$label, $rows->get($value)->total ?? 0];
        }

        $this->table(['Статус', 'Количество'], $tableRows);
    }

    private function printAnalysesStatus(): void
    {
        $this->line('<fg=yellow>AI work (prc_process_work)</>');

        $rows = DB::table('prc_process_work')->whereIn('stage', ['screening', 'extraction', 'publication'])
            ->selectRaw('stage, state, count(*) as total')
            ->groupBy('stage', 'state')
            ->get();

        $groups = [
            AnalysisStep::Screening->value => 'Screening',
            AnalysisStep::Extraction->value => 'Extraction',
            'publication' => 'Publication',
        ];

        $statuses = [];
        foreach (WorkState::cases() as $state) {
            $statuses[$state->value] = $state->value;
        }

        $tableRows = [];
        foreach ($groups as $stepValue => $stepLabel) {
            $tableRows[] = ["<fg=cyan>{$stepLabel}</>", ''];

            foreach ($statuses as $statusValue => $statusLabel) {
                $count = $rows
                    ->first(fn ($row): bool => $row->stage === $stepValue && $row->state === $statusValue)
                    ->total ?? 0;

                $tableRows[] = ["  {$statusLabel}", $count];
            }
        }

        $this->table(['Стадия / Статус', 'Количество'], $tableRows);
    }

    /**
     * `last_error` хранит `"unprocessable:{reason}: {detail}"` (единый формат ВСЕХ 4 сайтов
     * записи — `ArchiveStrategy`/`SpreadsheetStrategy`/`ScreeningProcessor`/
     * `ExtractionOrchestrator`); `split_part` вытаскивает токен причины без detail
     * (Postgres допускает `GROUP BY` по алиасу выходной колонки).
     */
    private function printUnprocessableBreakdown(): void
    {
        $rows = Document::query()
            ->where('status', DocumentStatus::Unprocessable->value)
            ->toBase()
            ->selectRaw("split_part(last_error, ':', 2) as reason, count(*) as total")
            ->groupBy('reason')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('Unprocessable: 0');

            return;
        }

        $this->line('<fg=yellow>Unprocessable по причине</>');
        $this->table(['Причина', 'Количество'], $rows->map(fn ($row): array => [$row->reason ?: '—', $row->total])->all());
    }

    private function printBorderlineCount(): void
    {
        $count = Analysis::query()
            ->where('step', AnalysisStep::Screening->value)
            ->whereRaw("result->'decision'->>'reason' = ?", [ScreeningDecisionReason::BorderlineConfidence->value])
            ->count();

        $this->line("Borderline-скринингов (ждут оператора): {$count}");
    }
}
