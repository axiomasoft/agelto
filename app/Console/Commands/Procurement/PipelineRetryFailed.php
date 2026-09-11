<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\DownloadDocumentJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Services\Procurement\PipelineRecoveryReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Ретрай провалившихся стадий пайплайна (v2-аналог v1
 * `Old/app/Console/Commands/PipelineRetryFailedLinksCommand.php`): по `next_retry_at`
 * (`HasErrorBlock`), кроме `failed_permanently` (dead-letter — эта команда их не подбирает).
 * Document retry остаётся в этой команде, а весь AI retry и recovery потерянных continuations
 * принадлежит только `PipelineRecoveryReconciler`: он выводит следующий шаг из durable state.
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

    public function __construct(private readonly PipelineRecoveryReconciler $reconciler)
    {
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

        $downloadsDispatched = $this->retryDownloads($limit);
        $documentsDispatched = $this->retryDocuments($limit);
        $analysesDispatched = $this->reconciler->reconcile(limit: $limit);

        $this->info("Скачивания: {$downloadsDispatched}, документы: {$documentsDispatched}, анализы: {$analysesDispatched}");

        return self::SUCCESS;
    }

    private function retryDownloads(int $limit): int
    {
        $candidates = Document::query()
            ->where('status', DocumentStatus::Discovered)
            ->where('attempts', '>', 0)
            ->whereNull('removed_at')
            ->pendingRetry()
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($candidates as $document) {
            $claimed = Document::query()
                ->whereKey($document->id)
                ->where('status', DocumentStatus::Discovered->value)
                ->whereNull('removed_at')
                ->where('attempts', '>', 0)
                ->where('failed_permanently', false)
                ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
                ->update(['next_retry_at' => now()->addMinutes(self::CLAIM_HOLD_MINUTES)]);

            if ($claimed !== 1) {
                continue;
            }

            DownloadDocumentJob::dispatch($document->id)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
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
}
