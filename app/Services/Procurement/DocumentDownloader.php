<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\Document\FinalizeDocumentDownloadAction;
use App\Dto\Procurement\Crawler\StageSettings;
use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use App\Support\SecretRedactor;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class DocumentDownloader
{
    public function __construct(
        private ProxyResolver $proxyResolver,
        private ProxyBindingResolver $proxyBindingResolver,
        private PlatformRequestBudget $requestBudget,
        private CrawlSettingsResolver $crawlSettings,
        private DocumentStoreRepository $storeRepository,
        private FinalizeDocumentDownloadAction $finalizeDownload,
        private FileLocationResolver $locationResolver,
        private SourceTlsTrust $tlsTrust,
        private SourceTargetValidator $targetValidator,
        private OperationLogWriter $operationLogs,
        private FetchLogger $fetches,
    ) {}

    public function download(Document $document, ?float $deadlineAt = null): ?DocumentVersion
    {
        if ($document->source_url === null || $document->procurement_id === null) {
            return null;
        }

        $deadlineAt ??= microtime(true) + 240;
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
        } catch (PlatformBudgetExhaustedException) {
            $this->operationLogs->budgetExhausted($document->procurement_id);

            return null;
        } catch (Throwable $exception) {
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
        $settings = $this->crawlSettings->forStage(
            stage: CrawlStage::Document,
            platform: Platform::query()->findOrFail($document->platform_id),
        );
        $maxRedirects = $this->positiveIntegerConfig(key: 'crawler.documents_max_redirects', default: 3);

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->targetValidator->assertDocumentUrl(url: $url);
            $host = $this->canonicalHost(url: $url);
            $lease = $this->documentLease(document: $document, host: $host);
            $response = $this->requestWithRetries(document: $document, lease: $lease, url: $url, host: $host, settings: $settings, deadlineAt: $deadlineAt);

            try {
                if ($this->isRedirect(response: $response)) {
                    $location = $response->header('Location');

                    if ($location === '') {
                        throw new RuntimeException('Document redirect has no Location header.');
                    }

                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }

                if ($response->failed()) {
                    throw new RuntimeException("Не удалось скачать документ: HTTP {$response->status()}");
                }

                return $this->readBounded(response: $response, deadlineAt: $deadlineAt);
            } finally {
                $response->close();
            }
        }

        throw new RuntimeException('Document redirect limit exceeded.');
    }

    private function documentLease(Document $document, string $host): ProxyLease
    {
        return $this->proxyResolver->resolve(
            scope: ProxyScope::DocumentDownload,
            target: $host,
            requirements: $this->proxyBindingResolver->requirementsForProcurement($document->procurement_id),
        );
    }

    /**
     * Каждый HTTP-запрос, включая повтор после 429/5xx, берёт слот общего `PlatformRequestBudget`
     * и пишется в `prc_fetches`. Раньше повторы делал `Http::retry()` внутри одного слота: до пяти
     * запросов к площадке с фиксированной паузой вне бюджета вежливости — именно когда площадка
     * уже сигнализирует перегрузку.
     */
    private function requestWithRetries(Document $document, ProxyLease $lease, string $url, string $host, StageSettings $settings, float $deadlineAt): Response
    {
        $attempts = max(1, $settings->retries ?? 5);

        for ($attempt = 1; ; $attempt++) {
            BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
            $startedAt = hrtime(true);
            $waitMs = $this->requestBudget->acquire(host: $host, stage: CrawlStage::Document, deadlineAt: $deadlineAt);
            $response = $this->requestWithOutcome(lease: $lease, url: $url, settings: $settings, deadlineAt: $deadlineAt);

            try {
                $this->fetches->log(CrawlStage::Document, null, $document->procurement_id, [
                    'method' => 'document-download',
                    'request_params' => ['url' => $url],
                    'http_status' => $response->status(),
                    'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                    'proxy_id' => $lease->proxyId,
                    'proxy_source' => $lease->source?->value,
                    'wait_ms' => $waitMs,
                ]);
            } catch (Throwable $exception) {
                $response->close();
                throw $exception;
            }

            if ($attempt >= $attempts || ! $this->isRetryable(response: $response)) {
                return $response;
            }

            $response->close();
            $retryDelayMs = max(0, $settings->retryDelayMs ?? 500);

            if ($retryDelayMs > 0) {
                BoundedResponseReader::assertTime(deadlineAt: $deadlineAt - $retryDelayMs / 1000);
                Sleep::for($retryDelayMs)->milliseconds();
            }
        }
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->status() >= 500;
    }

    private function request(string $url, ProxyLease $lease, StageSettings $settings, float $deadlineAt): Response
    {
        BoundedResponseReader::assertTime(deadlineAt: $deadlineAt);
        $timeout = max(0.001, min($settings->timeoutSeconds ?? 30, $deadlineAt - microtime(true)));
        $tlsOptions = $this->tlsTrust->optionsFor($this->canonicalHost(url: $url));

        return Http::withOptions([
            ...$lease->httpOptions(),
            ...$tlsOptions,
            'allow_redirects' => false,
            'stream' => true,
            'read_timeout' => min(1, $timeout),
        ])
            ->withUserAgent($this->stringConfig(key: 'crawler.user_agent'))
            ->timeout($timeout)
            ->get($url);
    }

    private function requestWithOutcome(ProxyLease $lease, string $url, StageSettings $settings, float $deadlineAt): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->request(url: $url, lease: $lease, settings: $settings, deadlineAt: $deadlineAt);
        } catch (ConnectionException $exception) {
            $this->proxyResolver->reportOutcome($lease, ProxyOutcome::failure($this->connectionFailureReason($exception)));

            throw $exception;
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        try {
            $this->proxyResolver->reportOutcome($lease, $this->outcomeFrom(response: $response, latencyMs: $latencyMs));
        } catch (Throwable $exception) {
            $response->close();
            throw $exception;
        }

        return $response;
    }

    private function outcomeFrom(Response $response, int $latencyMs): ProxyOutcome
    {
        return match (true) {
            $response->status() === 403 => ProxyOutcome::failure(ProxyFailureReason::Http403),
            $response->status() === 429 => ProxyOutcome::failure(ProxyFailureReason::Http429),
            $response->status() === 407 => ProxyOutcome::failure(ProxyFailureReason::ProxyAuthFailed),
            default => ProxyOutcome::success(latencyMs: $latencyMs),
        };
    }

    private function connectionFailureReason(ConnectionException $exception): ProxyFailureReason
    {
        $message = SecretRedactor::text($exception->getMessage());

        return match (true) {
            str_contains($message, 'SSL') => ProxyFailureReason::TlsError,
            str_contains($message, 'timed out') || str_contains($message, 'Timeout') => ProxyFailureReason::NetworkTimeout,
            default => ProxyFailureReason::ConnectFailed,
        };
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

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function canonicalHost(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
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

    private function stringConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new \UnexpectedValueException("{$key} must be a string.");
        }

        return $value;
    }
}
