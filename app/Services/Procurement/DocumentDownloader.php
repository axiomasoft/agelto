<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\Document\FinalizeDocumentDownloadAction;
use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Network\BoundedResponseReader;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class DocumentDownloader
{
    public function __construct(
        private LegacyOutbound $outbound,
        private ProxyBindingResolver $proxyBindingResolver,
        private DocumentStoreRepository $storeRepository,
        private FinalizeDocumentDownloadAction $finalizeDownload,
        private FileLocationResolver $locationResolver,
        private SourceTargetValidator $targetValidator,
        private OperationLogWriter $operationLogs,
    ) {}

    public function download(Document $document, ?float $deadlineAt = null): ?DocumentVersion
    {
        if ($document->source_url === null || $document->procurement_id === null) {
            return null;
        }

        $deadlineAt ??= microtime(true) + 300;
        $stream = null;

        try {
            $download = $this->fetch(document: $document, deadlineAt: $deadlineAt);
            $stream = $download['stream'];
            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
            $disk = $this->locationResolver->currentDocumentsDisk();
            $storagePath = $this->store(disk: $disk, hash: $download['hash'], stream: $stream, deadlineAt: $deadlineAt);

            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);

            return $this->finalizeDownload->execute($document, [
                'document_id' => $document->id,
                'content_hash' => $download['hash'],
                'size_bytes' => $download['size'],
                'filename' => $this->filenameFrom($document),
                'extension' => $this->extensionFrom($document),
                'mime' => MimeDetector::of($download['prefix']),
                'storage_path' => $storagePath,
                'disk' => $disk,
                'downloaded_at' => now(),
            ]);
        } catch (OutboundUnavailable|ConnectionException) {
            // Dependency/control failure does not consume the document's genuine item budget.
            // The request owner records transport facts; the existing document selector keeps it due.
            return null;
        } catch (PlatformBudgetExhaustedException) {
            $this->operationLogs->budgetExhausted($document->procurement_id);

            return null;
        } catch (Throwable $exception) {
            if (DatabaseFailureClassifier::isTransient($exception)
                || $exception instanceof DependencyUnavailable) {
                return null;
            }
            $this->operationLogs->documentFailed($document->id, $exception);
            $this->recordFailure(document: $document, exception: $exception);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @return array{stream: resource, hash: string, size: int, prefix: string} */
    private function fetch(Document $document, float $deadlineAt): array
    {
        $url = (string) $document->source_url;
        $this->targetValidator->assertDocumentUrl(url: $url);
        $platform = Platform::query()->findOrFail($document->platform_id);
        $platformCode = $platform->getAttribute('code');
        if (! is_string($platformCode)) {
            throw new \LogicException('Platform code is unavailable.');
        }
        $response = $this->outbound->request(
            callerCode: 'legacy.document_download', platformId: $platform->id, providerKey: null,
            capability: 'download', url: new SafeUrl($url),
            trafficClass: TrafficClass::Download,
            routePolicy: $this->proxyBindingResolver->forProcurement($document->procurement_id),
            controls: ['system', 'documents', 'documents:platform:'.$platformCode],
            deadlineSeconds: min(420, max(1, (int) floor($deadlineAt - microtime(true)))),
        );
        try {
            if ($response->failed()) {
                throw new RuntimeException("Не удалось скачать документ: HTTP {$response->status()}");
            }

            return $this->readBounded(response: $response, deadlineAt: $deadlineAt);
        } finally {
            $response->close();
        }
    }

    /** @return array{stream: resource, hash: string, size: int, prefix: string} */
    private function readBounded(Response $response, float $deadlineAt): array
    {
        $maxBytes = $this->positiveIntegerConfig(key: 'crawler.documents_max_bytes', default: 104_857_600);
        $declaredLength = $response->header('Content-Length');

        if (ctype_digit($declaredLength) && (int) $declaredLength > $maxBytes) {
            throw new RuntimeException("Document exceeds the {$maxBytes}-byte limit.");
        }

        $input = $response->toPsrResponse()->getBody();

        if ($input->isSeekable()) {
            $input->rewind();
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');

        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot allocate a bounded document stream.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $prefix = '';

        try {
            while (! $input->eof()) {
                BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
                $chunk = $input->read(8192);
                BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);

                if ($chunk === '') {
                    BoundedResponseReader::assertComplete($input);
                    break;
                }

                $size += strlen($chunk);

                if ($size > $maxBytes) {
                    throw new RuntimeException("Document exceeds the {$maxBytes}-byte limit.");
                }

                hash_update($hash, $chunk);
                $prefix .= substr($chunk, 0, max(0, 8192 - strlen($prefix)));

                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot buffer the downloaded document.');
                }
            }

            if ($size === 0) {
                throw new RuntimeException('Downloaded document is empty.');
            }

            rewind($stream);

            return [
                'stream' => $stream,
                'hash' => hash_final($hash),
                'size' => $size,
                'prefix' => $prefix,
            ];
        } catch (Throwable $exception) {
            fclose($stream);

            throw $exception;
        }
    }

    /** @param resource $stream */
    private function store(string $disk, string $hash, $stream, float $deadlineAt): string
    {
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";
        $storage = Storage::disk($disk);
        $stagingPath = "{$path}.staging.".Str::uuid();

        try {
            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
            rewind($stream);

            if ($storage->writeStream(path: $stagingPath, resource: $stream) !== true) {
                throw new RuntimeException("Не удалось сохранить staging document: {$stagingPath}");
            }

            if (! hash_equals($hash, $this->hashStored(storage: $storage, path: $stagingPath, deadlineAt: $deadlineAt))) {
                throw new RuntimeException("Object storage вернул неполный staging document: {$stagingPath}");
            }

            $published = $storage->exists(path: $path)
                && $this->storedMatches(storage: $storage, path: $path, expectedHash: $hash, deadlineAt: $deadlineAt);

            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
            if (! $published) {
                $staged = $storage->readStream(path: $stagingPath);

                if (! is_resource($staged)) {
                    throw new RuntimeException("Object storage не открыл staging document: {$stagingPath}");
                }

                try {
                    $written = $storage->writeStream(path: $path, resource: $staged);
                } finally {
                    fclose($staged);
                }

                if ($written !== true && ! $this->storedMatches(storage: $storage, path: $path, expectedHash: $hash, deadlineAt: $deadlineAt)) {
                    throw new RuntimeException("Не удалось опубликовать document: {$path}");
                }
            }

            if (! hash_equals($hash, $this->hashStored(storage: $storage, path: $path, deadlineAt: $deadlineAt))) {
                throw new RuntimeException("Object storage вернул неполный или другой документ: {$path}");
            }
        } finally {
            if ($storage->exists(path: $stagingPath)) {
                $storage->delete($stagingPath);
            }
        }

        return $path;
    }

    private function storedMatches(Filesystem $storage, string $path, string $expectedHash, float $deadlineAt): bool
    {
        try {
            return hash_equals($expectedHash, $this->hashStored(storage: $storage, path: $path, deadlineAt: $deadlineAt));
        } catch (OutboundRequestDeadlineExceeded $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    private function hashStored(Filesystem $storage, string $path, float $deadlineAt): string
    {
        BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
        $stored = $storage->readStream(path: $path);

        if (! is_resource($stored)) {
            throw new RuntimeException("Object storage не открыл документ для проверки: {$path}");
        }

        try {
            $storedHash = hash_init('sha256');

            while (! feof($stored)) {
                BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
                $chunk = fread($stored, 8192);

                BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
                if ($chunk === false || ($chunk === '' && ! feof($stored))) {
                    throw new RuntimeException("Object storage не прочитал документ: {$path}");
                }

                hash_update($storedHash, $chunk);
            }

            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);

            return hash_final($storedHash);
        } finally {
            fclose($stored);
        }
    }

    private function recordFailure(Document $document, Throwable $exception): void
    {
        $attempts = $document->attempts + 1;
        $maxAttempts = $this->positiveIntegerConfig(key: 'crawler.documents_max_attempts', default: 5);
        $backoffMinutes = $this->positiveIntegerConfig(key: 'crawler.documents_retry_backoff_minutes', default: 15);
        $failedPermanently = $attempts >= $maxAttempts;

        $this->storeRepository->update($document, [
            'attempts' => $attempts,
            'last_error' => SecretRedactor::text($exception->getMessage()),
            'last_error_at' => now(),
            'next_retry_at' => $failedPermanently ? null : now()->addMinutes($backoffMinutes * $attempts),
            'failed_permanently' => $failedPermanently,
        ]);
    }

    private function filenameFrom(Document $document): string
    {
        return $document->title !== '' ? $document->title : "document-{$document->id}";
    }

    private function extensionFrom(Document $document): string
    {
        $extension = pathinfo($this->filenameFrom($document), PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : 'bin';
    }

    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
