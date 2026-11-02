<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Document\DocumentFetchPlan;
use App\Dto\Procurement\Document\DownloadResult;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\DownloadStorageRepository;
use App\Services\Procurement\Platform\Contracts\DocumentAccessResolver;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Support\Files\MimeDetector;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/** Source access ends at verified immutable bytes. Publication is exclusively WorkFinalizer's job. */
final readonly class DownloadHandler implements StageHandler
{
    public function __construct(private DocumentAccessResolver $access, private PlatformOutbound $outbound,
        private DownloadStorageRepository $storage, private DocumentVersionRepository $versions,
        private BlobStore $blobs, private ArtifactGarbageCollector $gc, private FetchOutcomeBridge $bridge,
        private DownloadPolicy $policy, private ProcessClock $clock) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        try {
            return $this->download($input, $execution);
        } catch (FilesystemException $failure) {
            throw new DependencyUnavailable('storage', $failure);
        }
    }

    private function download(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof DownloadInput) {
            throw new \InvalidArgumentException('Download input required.');
        }
        $execution->tick();
        $head = $this->versions->head($input->documentId);
        $plan = $this->access->resolve($input, $execution);
        if (! $plan instanceof DocumentFetchPlan) {
            return $this->bridge->failure($plan);
        }
        if ($plan->request->profile->maxEntityBytes > $this->policy->maxBytes) {
            throw new \LogicException('Document profile exceeds the reserved stream ceiling.');
        }
        $reservations = $this->storage->reserve($execution->lease());
        if ($reservations instanceof StageBlocked) {
            return $reservations;
        }
        if ($reservations === null) {
            return $this->defer(DeferralReason::StorageUnavailable);
        }
        $initialSends = $this->storage->sends($execution->lease());
        $sendCeiling = $plan->request->sendLimit ?? $plan->request->maxAttempts() * ($plan->request->profile->redirects + 1);
        $binary = null;
        $stream = null;
        $published = false;
        try {
            for ($refresh = 0; $refresh <= 1; $refresh++) {
                $remaining = $sendCeiling - ($this->storage->sends($execution->lease()) - $initialSends);
                if ($remaining < 1) {
                    return $this->defer(DeferralReason::TargetCooldown);
                }
                $request = $plan->request;
                $bounded = new OutboundRequestPlan($request->physicalRequestId, $request->targetId, $request->method, $request->url,
                    $request->profile, $request->trafficClass, $request->idempotency, $request->bodyHash,
                    min($remaining, $request->sendLimit ?? $remaining), $request->adapterClaim);
                $outcome = $this->outbound->execute($bounded, $execution);
                if ($outcome instanceof TransportDeferred) {
                    return $this->bridge->failure(new FetchDeferred($outcome->reason, $outcome->retryAt));
                }
                if ($outcome instanceof TransportBlocked) {
                    return $this->bridge->failure(new FetchBlocked($outcome->reason));
                }
                if (! $outcome instanceof TransportSucceeded || ! $outcome->response->body instanceof BinaryBodyArtifact) {
                    throw new \LogicException('Download requires bounded binary transport.');
                }
                $binary = $outcome->response->body;
                $response = $outcome->response;
                if ($refresh === 0 && in_array($response->status, [401, 403, 410], true)) {
                    if (! $this->removeBinary($binary)) {
                        throw new ArtifactUnavailable('Cannot clean expired reference response.');
                    }
                    $binary = null;
                    $resolved = $this->access->resolve($input, $execution, refresh: true);
                    if (! $resolved instanceof DocumentFetchPlan) {
                        return $this->bridge->failure($resolved);
                    }
                    if ($resolved->request->profile != $plan->request->profile) {
                        throw new \LogicException('Signed reference refresh cannot change the download resource policy.');
                    }
                    $plan = $resolved;

                    continue;
                }
                if ($response->status !== 200 || $binary->bytes < 1 || $binary->bytes > $this->policy->maxBytes) {
                    return new StageBlocked(BlockedReason::PolicyReview);
                }
                $length = $response->header('Content-Length');
                // Content-Length describes encoded transfer bytes when Content-Encoding is present.
                if (($response->header('Content-Encoding') === null || $response->header('Content-Encoding') === 'identity')
                    && $length !== null && (! ctype_digit($length) || (int) $length !== $binary->bytes)) {
                    return $this->defer(DeferralReason::TargetCooldown);
                }
                if (($plan->expectedBytes !== null && $plan->expectedBytes !== $binary->bytes)
                    || ($plan->expectedHash !== null && $plan->expectedHash->hex !== $binary->artifact->sha256->hex)) {
                    return new StageBlocked(BlockedReason::PolicyReview);
                }
                $stream = Storage::disk($binary->artifact->disk)->readStream($binary->artifact->key);
                if (! is_resource($stream)) {
                    throw new ArtifactUnavailable('Downloaded stream unavailable.');
                }
                $prefix = fread($stream, 8192);
                if ($prefix === false) {
                    throw new ArtifactUnavailable('Downloaded stream unreadable.');
                }
                rewind($stream);
                $mime = MimeDetector::of($prefix);
                if (in_array($mime, ['text/html', 'application/xhtml+xml'], true) || preg_match('/^\s*(?:<!doctype\s+html|<html|<head|<body|<form)\b/i', $prefix)) {
                    return new StageBlocked(BlockedReason::PolicyReview);
                }
                $artifact = $this->blobs->put($reservations->durable, $stream, $reservations->temporary, $execution, cleanupTemporary: false);
                if ($artifact->sizeBytes !== $binary->bytes || $artifact->hash->hex !== $binary->artifact->sha256->hex) {
                    throw new ArtifactUnavailable('Transport artifact checksum or size mismatch.');
                }
                $this->blobs->verifyExisting($artifact, $execution);
                $modified = $response->header('Last-Modified');
                $lastModified = $modified === null ? false : \DateTimeImmutable::createFromFormat(DATE_RFC7231, $modified);

                $published = true;

                return new StageSucceeded(new DownloadResult($head, $artifact, new SourceDocumentMetadata($plan->filename, $mime,
                    $response->header('ETag'), $lastModified === false ? null : $lastModified)));
            }
            throw new \LogicException('Signed reference refresh exceeded its bound.');
        } catch (ArtifactUnavailable|FilesystemException) {
            return $this->defer(DeferralReason::StorageUnavailable);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // A failed delete retains the temporary charge. An uncertain writer is never released.
            if ($binary === null || $this->removeBinary($binary)) {
                $this->gc->cleanup($reservations->temporary);
            }
            if (! $published) {
                $this->gc->cleanup($reservations->durable);
            }
            // Successful staged original remains charged until guarded transfer or orphan GC.
        }
    }

    private function removeBinary(BinaryBodyArtifact $body): bool
    {
        $disk = Storage::disk($body->artifact->disk);

        return $disk->delete($body->artifact->key) && ! $disk->exists($body->artifact->key);
    }

    private function defer(DeferralReason $reason): StageDeferred
    {
        return new StageDeferred($reason, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds')));
    }
}
