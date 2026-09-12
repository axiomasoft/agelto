<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\DownloadDocumentJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Durable bridge from discovered attachments to the document pipeline.
 *
 * Both the direct detail path and the scheduled backlog use the same conditional claim.
 * A future retry timestamp is only a dispatch hold; attempts are owned by the executing job.
 */
final readonly class PipelineDispatcher
{
    private const int ClaimHoldMinutes = 10;

    public function dispatch(Procurement $procurement): int
    {
        $limit = $this->configuredLimit();
        $downloads = $this->enqueueDownloads(
            query: $this->discoveredQuery()->where('procurement_id', $procurement->id),
            limit: $limit,
        );

        return $downloads + $this->enqueuePreparations(
            query: $this->downloadedQuery()->where('procurement_id', $procurement->id),
            limit: $limit - $downloads,
        );
    }

    public function dispatchBacklog(int $limit): int
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('The dispatch limit must be a positive integer.');
        }

        $downloads = $this->enqueueDownloads(query: $this->discoveredQuery(), limit: $limit);

        return $downloads + $this->enqueuePreparations(
            query: $this->downloadedQuery(),
            limit: $limit - $downloads,
        );
    }

    public function dispatchDiscoveredBacklog(int $limit): int
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('The dispatch limit must be a positive integer.');
        }

        return $this->enqueueDownloads(query: $this->discoveredQuery(), limit: $limit);
    }

    /** @return Builder<Document> */
    private function discoveredQuery(): Builder
    {
        return Document::query()
            ->where('status', DocumentStatus::Discovered)
            ->whereNotNull('procurement_id')
            ->whereNotNull('source_url')
            ->where('source_url', '<>', '')
            ->whereNull('removed_at')
            ->pendingRetry()
            ->where(fn ($query) => $query
                ->whereNull('locked_until')
                ->orWhere('locked_until', '<=', now())
            )
            ->orderBy('id');
    }

    /** @return Builder<Document> */
    private function downloadedQuery(): Builder
    {
        return Document::query()
            ->where('status', DocumentStatus::Downloaded)
            ->whereNull('removed_at')
            ->pendingRetry()
            ->orderBy('id');
    }

    /** @param Builder<Document> $query */
    private function enqueueDownloads(Builder $query, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $dispatched = 0;

        foreach ($query->limit($limit)->get() as $document) {
            $claimed = Document::query()
                ->whereKey($document->id)
                ->where('status', DocumentStatus::Discovered->value)
                ->whereNotNull('procurement_id')
                ->whereNotNull('source_url')
                ->where('source_url', '<>', '')
                ->whereNull('removed_at')
                ->where('failed_permanently', false)
                ->where(fn ($query) => $query
                    ->whereNull('locked_until')
                    ->orWhere('locked_until', '<=', now())
                )
                ->where(fn ($query) => $query
                    ->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now())
                )
                ->update(['next_retry_at' => now()->addMinutes(self::ClaimHoldMinutes)]);

            if ($claimed !== 1) {
                continue;
            }

            DownloadDocumentJob::dispatch(documentId: $document->id)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }

    /** @param Builder<Document> $query */
    private function enqueuePreparations(Builder $query, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $documents = $query->limit($limit)->get();

        foreach ($documents as $document) {
            PrepareDocumentJob::dispatch(documentId: $document->id)->afterCommit();
        }

        return $documents->count();
    }

    private function configuredLimit(): int
    {
        $limit = config('ai.pipeline.dispatch_batch_limit', 100);

        if (! is_int($limit) || $limit <= 0) {
            throw new \LogicException('ai.pipeline.dispatch_batch_limit must be a positive integer.');
        }

        return $limit;
    }
}
