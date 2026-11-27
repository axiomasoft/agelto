<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Acquisition\Fetch\FetchBlocked;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Document\Download\DocumentFetchPlan;
use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Download\DownloadReservations;
use App\Dto\Procurement\Document\Download\DownloadResult;
use App\Dto\Procurement\Document\Download\SourceDocumentMetadata;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\BinaryBodyArtifact;
use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\DownloadStorageRepository;
use App\Services\Procurement\Platform\Contracts\DocumentAccessResolver;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
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
        $binary = null;
        $stream = null;
        $published = false;
        $refreshed = false;
        try {
            $initialSends = $this->storage->sends($execution->lease());
            $sendCeiling = $plan->request->sendLimit ?? $plan->request->maxAttempts() * ($plan->request->profile->redirects + 1);
            while (true) {
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
                if (! $refreshed && in_array($response->status, [401, 403, 410], true)) {
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
                    $refreshed = true;

                    continue;
                }
                $validationFailure = $this->validateResponse($plan, $response, $binary);
                if ($validationFailure !== null) {
                    return $validationFailure;
                }
                $stream = Storage::disk($binary->artifact->disk)->readStream($binary->artifact->key);
                if (! is_resource($stream)) {
                    throw new ArtifactUnavailable('Downloaded stream unavailable.');
                }
                $mime = $this->detectSafeMime($stream);
                if ($mime instanceof StageBlocked) {
                    return $mime;
                }

                return $this->persistVerifiedDownload($head, $plan, $reservations, $response, $binary, $stream, $mime, $execution, $published);
            }
        } catch (ArtifactUnavailable|FilesystemException) {
            return $this->defer(DeferralReason::StorageUnavailable);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // A failed delete retains the temporary charge. An uncertain writer is never released.
            try {
                if ($binary === null || $this->removeBinary($binary)) {
                    $this->gc->cleanup($reservations->temporary);
                }
            } catch (\Throwable $failure) {
                ProcessLog::dependencyUnavailable('storage', $failure);
            }
            if (! $published) {
                try {
                    $this->gc->cleanup($reservations->durable);
                } catch (\Throwable $failure) {
                    ProcessLog::dependencyUnavailable('storage', $failure);
                }
            }
            // Successful staged original remains charged until guarded transfer or orphan GC.
        }
    }

    private function validateResponse(DocumentFetchPlan $plan, ResponseEnvelope $response, BinaryBodyArtifact $binary): ?StageOutcome
    {
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

        return null;
    }

    /** @param resource $stream */
    private function detectSafeMime($stream): string|StageBlocked
    {
        $prefix = fread($stream, 8192);
        if ($prefix === false) {
            throw new ArtifactUnavailable('Downloaded stream unreadable.');
        }
        if (! @rewind($stream)) {
            throw new ArtifactUnavailable('Downloaded stream cannot be rewound.');
        }
        $mime = MimeDetector::of($prefix);
        if (in_array($mime, ['text/html', 'application/xhtml+xml'], true) || preg_match('/^\s*(?:<!doctype\s+html|<html|<head|<body|<form)\b/i', $prefix)) {
            return new StageBlocked(BlockedReason::PolicyReview);
        }

        return $mime;
    }

    /** @param resource $stream */
    private function persistVerifiedDownload(
        DocumentHead $head,
        DocumentFetchPlan $plan,
        DownloadReservations $reservations,
        ResponseEnvelope $response,
        BinaryBodyArtifact $binary,
        $stream,
        string $mime,
        ExecutionContext $execution,
        bool &$published,
    ): StageSucceeded {
        $artifact = $this->blobs->put($reservations->durable, $stream, $reservations->temporary, $execution, cleanupTemporary: false);
        if ($artifact->sizeBytes !== $binary->bytes || $artifact->hash->hex !== $binary->artifact->sha256->hex) {
            throw new ArtifactUnavailable('Transport artifact checksum or size mismatch.');
        }
        $this->blobs->verifyExisting($artifact, $execution);
        $modified = $response->header('Last-Modified');
        $lastModified = $modified === null ? false : \DateTimeImmutable::createFromFormat(DATE_RFC7231, $modified);

        $published = true;

        return new StageSucceeded(new DownloadResult($head, $artifact, new SourceDocumentMetadata(
            $this->filename($plan->filename, $response->header('Content-Disposition')), $mime,
            $response->header('ETag'), $lastModified === false ? null : $lastModified)));
    }

    private function filename(string $fallback, ?string $disposition): string
    {
        if ($disposition === null || $disposition === '') {
            return $fallback;
        }
        $raw = preg_match('/filename\*\s*=\s*UTF-8\'\'([^;\r\n]+)/i', $disposition, $m) === 1
            ? rawurldecode(trim($m[1], " \t\"'"))
            : (preg_match('/filename\s*=\s*"([^"\r\n]+)"/i', $disposition, $m) === 1
                || preg_match('/filename\s*=\s*([^;\s\r\n]+)/i', $disposition, $m) === 1
                ? rawurldecode(trim($m[1], " \t\"'")) : '');
        $candidate = basename(str_replace('\\', '/', trim($raw)));

        return $candidate !== '' && ! str_contains($candidate, "\0") && strlen($candidate) <= 255
            && mb_check_encoding($candidate, 'UTF-8') && pathinfo($candidate, PATHINFO_EXTENSION) !== ''
            ? $candidate : $fallback;
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
