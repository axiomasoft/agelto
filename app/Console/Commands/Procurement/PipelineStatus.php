<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use Illuminate\Console\Command;

/**
 * Перенос v1-наблюдаемости (`Old/app/Console/Commands/PipelineStatusCommand.php`) на v2-модель:
 * сводка документов по стадиям (`prc_documents.status`) и AI-анализов по (шаг, статус)
 * (`prc_ai_analyses`), плюс два v2-специфичных счётчика — разбивка `unprocessable` по причине
 * (D32/D38) и borderline-скринингов, ожидающих операторского ревью (D36,
 * `ScreeningOutcomeHandler::flagNeedsReview()`). Read-only снимок текущего состояния — без
 * временных окон, в отличие от `pipeline:stuck-check` (P7.3).
 */
final class PipelineStatus extends Command
{
    protected $signature = 'pipeline:status';

    protected $description = 'Показывает текущее состояние пайплайна документов и AI-анализа по всем стадиям';

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
            $tableRows[] = [$label, $rows->get($value)?->total ?? 0];
        }

        $this->table(['Статус', 'Количество'], $tableRows);
    }

    private function printAnalysesStatus(): void
    {
        $this->line('<fg=yellow>AI-анализы (prc_ai_analyses)</>');

        $rows = Analysis::query()
            ->toBase()
            ->selectRaw('step, status, count(*) as total')
            ->groupBy('step', 'status')
            ->get();

        $groups = [
            AnalysisStep::Screening->value => 'Screening',
            AnalysisStep::Extraction->value => 'Extraction',
        ];

        $statuses = [
            AnalysisStatus::Pending->value => 'Pending',
            AnalysisStatus::Running->value => 'Running',
            AnalysisStatus::Succeeded->value => 'Succeeded',
            AnalysisStatus::Failed->value => 'Failed',
            AnalysisStatus::Skipped->value => 'Skipped',
        ];

        $tableRows = [];
        foreach ($groups as $stepValue => $stepLabel) {
            $tableRows[] = ["<fg=cyan>{$stepLabel}</>", ''];

            foreach ($statuses as $statusValue => $statusLabel) {
                $count = $rows
                    ->first(fn ($row): bool => $row->step === $stepValue && $row->status === $statusValue)
                    ?->total ?? 0;

                $tableRows[] = ["  {$statusLabel}", $count];
            }
        }

        $this->table(['Стадия / Статус', 'Количество'], $tableRows);
    }

    /**
     * `last_error` хранит `"unprocessable:{reason}: {detail}"` (единый формат ВСЕХ 4 сайтов
     * записи — `ArchiveStrategy`/`SpreadsheetStrategy`/`ScreeningProcessor`/
     * `ExtractionOrchestrator`, D32/D38); `split_part` вытаскивает токен причины без detail
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
            ->whereRaw("(result->>'needs_review')::boolean = true")
            ->count();

        $this->line("Borderline-скринингов (needs_review, ждут оператора): {$count}");
    }
}
