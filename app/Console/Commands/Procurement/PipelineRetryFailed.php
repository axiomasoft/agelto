<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PipelineStage;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Ретрай провалившихся стадий пайплайна (v2-аналог v1
 * `Old/app/Console/Commands/PipelineRetryFailedLinksCommand.php`): по `next_retry_at`
 * (`HasErrorBlock`), кроме `failed_permanently` (dead-letter — эта команда их не подбирает).
 * Две НЕЗАВИСИМЫЕ секции — документы (стадия prepare) и AI-анализы (screening/extraction по
 * `step`) — не смешиваются в одну выборку (Code Guidance): у них разные модели/таблицы и
 * разные job'ы-получатели.
 *
 * Атомарный claim — условный `UPDATE ... WHERE next_retry_at <= now()`, сдвигающий
 * `next_retry_at` вперёд (НЕ новый статус — `DocumentStatus`/`AnalysisStatus` не меняются,
 * Implementation Rules P4.3: «новый промежуточный статус НЕ вводить»): конкурентный запуск
 * этой же команды (или второй тик планировщика) видит уже сдвинутый `next_retry_at` и
 * пропускает запись — диспатчится только реально захваченная строка (v1-канон, `:66`).
 */
#[Description('Повторно запускает провалившиеся стадии пайплайна (документы/анализы) с нужной стадии по next_retry_at')]
#[Signature('pipeline:retry-failed {--limit= : Максимум записей за секцию (документы/анализы); по умолчанию config(ai.pipeline.retry_batch_limit)}')]
final class PipelineRetryFailed extends Command
{
    /**
     * Держим захваченную запись «в работе» это время — второй параллельный claim в течение
     * него не пройдёт; если job зависнет дольше, следующий тик ретрая (`everyTenMinutes`,
     * `routes/console.php`) подхватит её снова — не хуже v1 (там аналогично не было отдельного
     * lease на сам claim, только атомарный UPDATE статуса).
     */
    private const int CLAIM_HOLD_MINUTES = 10;

    public function __construct(
        private readonly AnalysisRunStoreRepository $runs,
        private readonly DocumentAnalyzerRegistry $analyzers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limitOption = $this->option('limit');
        $configuredLimit = config('ai.pipeline.retry_batch_limit', 50);

        if (! is_int($configuredLimit) || $configuredLimit <= 0) {
            throw new \UnexpectedValueException('ai.pipeline.retry_batch_limit must be a positive integer.');
        }

        $limit = is_numeric($limitOption) ? (int) $limitOption : $configuredLimit;

        if ($limit <= 0) {
            throw new \UnexpectedValueException('The retry limit must be a positive integer.');
        }

        $documentsDispatched = $this->retryDocuments($limit);
        $analysesDispatched = $this->retryAnalyses($limit);

        $this->info("Документы: {$documentsDispatched}, анализы: {$analysesDispatched}");

        return self::SUCCESS;
    }

    private function retryDocuments(int $limit): int
    {
        $candidates = Document::query()
            ->where('status', DocumentStatus::Downloaded)
            ->where('attempts', '>', 0)
            ->pendingRetry()
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($candidates as $document) {
            $claimed = Document::query()
                ->whereKey($document->id)
                ->where('status', DocumentStatus::Downloaded->value)
                ->where('failed_permanently', false)
                ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
                ->update(['next_retry_at' => now()->addMinutes(self::CLAIM_HOLD_MINUTES)]);

            if ($claimed !== 1) {
                continue;
            }

            PrepareDocumentJob::dispatch($document->id)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }

    private function retryAnalyses(int $limit): int
    {
        $candidates = Analysis::query()
            ->where('status', AnalysisStatus::Failed)
            ->where('attempts', '>', 0)
            ->pendingRetry()
            ->with('documentVersion')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($candidates as $analysis) {
            $claimed = Analysis::query()
                ->whereKey($analysis->id)
                ->where('status', AnalysisStatus::Failed->value)
                ->where('failed_permanently', false)
                ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
                ->update(['next_retry_at' => now()->addMinutes(self::CLAIM_HOLD_MINUTES)]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                $this->dispatchAnalysisRetry($analysis);
                $dispatched++;
            } catch (LogicException $exception) {
                // Резолв анализатора извлечения провалился (тип документа не определён/без
                // extraction) — claim уже сдвинул next_retry_at, следующий тик подберёт запись
                // снова. Не роняем весь batch на одной записи.
                $this->warn($exception->getMessage());
            }
        }

        return $dispatched;
    }

    private function dispatchAnalysisRetry(Analysis $analysis): void
    {
        $documentVersion = $analysis->documentVersion;

        if ($documentVersion === null) {
            throw new LogicException("Анализ #{$analysis->id}: не найдена версия документа.");
        }

        $documentId = $documentVersion->document_id;
        $stage = PipelineStage::fromAnalysisStep($analysis->step);

        match ($stage) {
            // Launch-точка (§3.5 root/document-contour.md, D93) — плановый ретрай очереди,
            // mode=auto: старый прогон завершён (иначе status=Failed не проставился бы,
            // AnalysisStoreRepository::maybeTerminateRun), новый заводится перед передиспатчем.
            PipelineStage::Screening => $this->retryScreening($analysis, $documentId),
            // Д-4 (P7.7, research/10 §3): раньше здесь была заглушка «extraction ещё не
            // реализована (P6)» — EstimateExtractionJob существует с e06434c, ветка лгала и
            // проваленный extraction никогда не передиспатчился.
            PipelineStage::Extraction => $this->retryExtraction($analysis, $documentId),
            PipelineStage::Prepare => throw new LogicException(
                "Анализ #{$analysis->id}: недостижимо — PipelineStage::fromAnalysisStep никогда не возвращает Prepare.",
            ),
        };
    }

    private function retryScreening(Analysis $analysis, int $documentId): void
    {
        $this->runs->start($analysis->document_version_id, RunMode::Auto);

        ScreeningJob::dispatch($documentId)->afterCommit();
    }

    /**
     * Launch-точка стадии extraction — тот же приём D93, что `retryScreening()`: старый прогон
     * уже терминирован (`status=Failed` ставит его `AnalysisStoreRepository::maybeTerminateRun`
     * при ЛЮБОЙ провалившейся стадии, не только screening), новый заводится перед передиспатчем.
     * Диспатч — через реестр (`extractionAnalyzer()`), тот же приём, что
     * `RerunAnalysisAction::extractionAnalyzer()` (D93): ядро не называет `EstimateExtractionJob`
     * поимённо.
     */
    private function retryExtraction(Analysis $analysis, int $documentId): void
    {
        $this->runs->start($analysis->document_version_id, RunMode::Auto);

        $this->extractionAnalyzer($analysis)->dispatchExtraction($documentId);
    }

    /**
     * Собственный `document_type` у анализа извлечения в боевом контуре пуст
     * (`AnalysisStoreRepository::firstOrCreate()` его не пишет) — ось берётся оттуда, где её
     * ЗАПОЛНИЛИ: с активного анализа скрининга той же версии документа (тот же fallback, что
     * `RerunAnalysisAction::extractionAnalyzer()`).
     */
    private function extractionAnalyzer(Analysis $analysis): DocumentAnalyzer
    {
        $documentType = $analysis->document_type ?? Analysis::query()
            ->active()
            ->where('document_version_id', $analysis->document_version_id)
            ->where('step', AnalysisStep::Screening)
            ->value('document_type');

        $type = is_string($documentType) ? AiDocumentType::tryFrom($documentType) : null;
        $analyzer = $type !== null ? $this->analyzers->for($type) : null;

        if ($analyzer === null || ! $analyzer->hasExtraction()) {
            throw new LogicException(
                "Анализ #{$analysis->id}: ретрай извлечения невозможен — тип документа «".(is_string($documentType) ? $documentType : '—').'» без анализатора со стадией извлечения.'
            );
        }

        return $analyzer;
    }
}
