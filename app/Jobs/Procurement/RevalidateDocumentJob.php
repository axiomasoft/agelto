<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\DocumentDownloader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Carbon;
use Throwable;

/** Re-fetches one existing EIS uid through the normal downloader/version lifecycle. */
#[Timeout(300)]
final class RevalidateDocumentJob extends PipelineJob implements ShouldBeUnique
{
    private ?Document $documentCache = null;

    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue('doc_download');
    }

    public function uniqueId(): string
    {
        return $this->pipelineUniqueId();
    }

    public function pipelineUniqueId(): string
    {
        return "{$this->documentId}:revalidate";
    }

    protected function statusGuardPassed(): bool
    {
        $document = $this->document();

        if ($document->source_url === null
            || $document->getAttribute('removed_at') !== null
            || (bool) $document->getAttribute('failed_permanently')) {
            return false;
        }

        $nextRetryAt = $document->getAttribute('next_retry_at');

        if ($nextRetryAt instanceof Carbon && $nextRetryAt->isFuture()) {
            return false;
        }

        return DocumentVersion::query()->where('document_id', $document->id)->exists();
    }

    protected function acquireLease(): bool
    {
        return app(DocumentStoreRepository::class)->acquireRevalidationLease(
            document: $this->document(),
            owner: self::class,
            ttlSeconds: $this->leaseSeconds(),
        );
    }

    protected function releaseLease(): void
    {
        $this->document()->releaseLease();
    }

    protected function recordFailure(Throwable $exception): void
    {
        $this->document()->recordFailure($exception->getMessage());
    }

    protected function process(): void
    {
        app(DocumentDownloader::class)->download($this->document());
    }

    private function document(): Document
    {
        return $this->documentCache ??= Document::query()->findOrFail($this->documentId);
    }
}
