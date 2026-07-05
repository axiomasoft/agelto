<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Pipeline\PipelineStage;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use App\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class PipelineRetryFailedLinksCommand extends Command
{
    protected $signature = 'app:pipeline:retry-failed-links {--limit=50 : Максимум анализов за один запуск}';

    protected $description = 'Повторно запускает провалившиеся анализы с нужной стадии (по next_retry_at, кроме dead-letter)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $analyses = DocumentAnalysis::query()
            ->whereIn('status', [
                AnalysisStatus::Error->value,
                AnalysisStatus::PrimaryAnalysisFailed->value,
                AnalysisStatus::EstimateAnalysisFailed->value,
            ])
            ->where('failed_permanently', false)
            ->where(fn ($q) => $q
                ->whereNull('next_retry_at')
                ->orWhere('next_retry_at', '<=', now())
            )
            ->limit($limit)
            ->get();

        if ($analyses->isEmpty()) {
            $this->info('Нет анализов для повторной обработки.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($analyses as $analysis) {
            $failedStatus = $analysis->status;

            // Перезапускаем только нужный хвост: упал на анализе сметы — не гоняем повторный скрининг.
            [$entryStatus, $jobs] = match ($failedStatus->stage()) {
                PipelineStage::DocumentPrepare => [AnalysisStatus::Preparing, [
                    new PrepareDocumentJob(documentAnalysisId: $analysis->id),
                    new PrimaryAnalysisJob(documentAnalysisId: $analysis->id),
                ]],
                PipelineStage::PrimaryAnalysis => [AnalysisStatus::Prepared, [
                    new PrimaryAnalysisJob(documentAnalysisId: $analysis->id),
                ]],
                PipelineStage::EstimateAnalysis => [AnalysisStatus::PrimaryAnalyzed, [
                    new EstimateAnalysisJob(documentAnalysisId: $analysis->id),
                ]],
            };

            // Атомарный claim: диспатчит только тот, кто перевёл запись из failed-статуса (анти-дубль).
            $claimed = DocumentAnalysis::query()
                ->whereKey($analysis->id)
                ->where('status', $failedStatus->value)
                ->update(['status' => $entryStatus->value]);

            if ($claimed !== 1) {
                continue;
            }

            Bus::chain($jobs)->dispatch();
            $dispatched++;
        }

        $this->info("Диспатчено повторных анализов: {$dispatched}");

        return self::SUCCESS;
    }
}
