<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Services\Procurement\DocumentDownloader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\Timeout;
use Throwable;

/**
 * Bounded owner of a deferred source-document download.
 *
 * The retry selector claims a due `Discovered` row before dispatch. The status guard and
 * lease make duplicate delivery harmless; the downloader remains the owner of HTTP, storage
 * and the document error block.
 */
#[Timeout(300)]
final class DownloadDocumentJob extends PipelineJob implements ShouldBeUnique
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
        return "{$this->documentId}:download";
    }

    protected function statusGuardPassed(): bool
    {
        return $this->document()->status === DocumentStatus::Discovered;
    }

    protected function acquireLease(): bool
    {
        return $this->document()->acquireLease(owner: self::class, ttlSeconds: $this->leaseSeconds());
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
        app(DocumentDownloader::class)->download(document: $this->document(), deadlineAt: microtime(true) + 240);
    }

    private function document(): Document
    {
        return $this->documentCache ??= Document::findOrFail($this->documentId);
    }
}
