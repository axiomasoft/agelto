<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Proxy\ProxyResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Скачивание версии файла документа: контент-адресация по `sha256`, дедуп в рамках документа
 * (`unique(document_id, content_hash)`) — совпал хэш → no-op (возврат существующей версии),
 * новый контент → новая версия + storage (immutable) + событие `document_added` (первая
 * версия документа) / `document_replaced` (последующая), `prc_documents.status=downloaded`.
 * Ошибка скачивания → error-блок `prc_documents`, не бросает исключение наружу (стадия
 * продолжает обработку остальных документов).
 */
final readonly class DocumentDownloader
{
    public function __construct(
        private ProxyResolver $proxyResolver,
        private PoliteThrottle $throttle,
        private DocumentStoreRepository $storeRepository,
        private ProcurementStoreRepository $procurementStore,
    ) {}

    public function download(Document $document): ?DocumentVersion
    {
        if ($document->source_url === null) {
            return null;
        }

        try {
            $content = $this->fetch($document->source_url);
        } catch (\Throwable $exception) {
            $this->recordFailure($document, $exception);

            return null;
        }

        $hash = hash('sha256', $content);

        $existing = $this->storeRepository->findVersionByHash(documentId: $document->id, contentHash: $hash);

        if ($existing !== null) {
            return $existing;
        }

        $isFirstVersion = $this->storeRepository->countVersions($document->id) === 0;

        $version = $this->storeRepository->createVersion([
            'document_id' => $document->id,
            'content_hash' => $hash,
            'size_bytes' => strlen($content),
            'filename' => $this->filenameFrom($document),
            'extension' => $this->extensionFrom($document),
            'mime' => $this->mimeFrom($content),
            'storage_path' => $this->store(hash: $hash, content: $content),
            'downloaded_at' => now(),
        ]);

        $this->storeRepository->update($document, ['status' => DocumentStatus::Downloaded]);

        $this->procurementStore->storeEvent(
            procurementId: $document->procurement_id,
            eventType: $isFirstVersion ? ProcurementEventType::DocumentAdded : ProcurementEventType::DocumentReplaced,
            occurredAt: now(),
            entityRef: ['document_id' => $document->id, 'document_version_id' => $version->id],
        );

        return $version;
    }

    private function fetch(string $url): string
    {
        $lease = $this->proxyResolver->resolve(ProxyScope::DocumentDownload, $this->canonicalHost($url));

        $this->throttle->wait();

        $response = $this->requestWithOutcome($lease, $url);

        if ($response->failed()) {
            throw new \RuntimeException("Не удалось скачать документ: HTTP {$response->status()}");
        }

        return $response->body();
    }

    private function request(string $url, ProxyLease $lease): Response
    {
        return Http::withOptions($lease->httpOptions())
            ->withUserAgent((string) config('crawler.user_agent'))
            ->timeout((int) config('crawler.http.timeout', 30))
            ->retry(
                times: (int) config('crawler.http.retries', 3),
                sleepMilliseconds: (int) config('crawler.http.base_delay_ms', 500),
                when: static fn (\Throwable $exception): bool => $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->status() >= 500),
                throw: false,
            )
            ->get($url);
    }

    /**
     * Оборачивает `request()` учётом исхода в резолвере (research/02 §9.1) — та же логика
     * маппинга причины, что и у `CsvSourceMethod` (диапазон вызовов не пересекается, общий
     * файл вне `Files` этого item'а не заводится).
     */
    private function requestWithOutcome(ProxyLease $lease, string $url): Response
    {
        try {
            $response = $this->request(url: $url, lease: $lease);
        } catch (ConnectionException $exception) {
            $this->proxyResolver->reportOutcome($lease, ProxyOutcome::failure($this->connectionFailureReason($exception)));

            throw $exception;
        }

        $this->proxyResolver->reportOutcome($lease, $this->outcomeFrom($response));

        return $response;
    }

    private function outcomeFrom(Response $response): ProxyOutcome
    {
        return match (true) {
            $response->status() === 403 => ProxyOutcome::failure(ProxyFailureReason::Http403),
            $response->status() === 429 => ProxyOutcome::failure(ProxyFailureReason::Http429),
            $response->status() === 407 => ProxyOutcome::failure(ProxyFailureReason::ProxyAuthFailed),
            $response->status() === 200 && trim($response->body()) === '' => ProxyOutcome::failure(ProxyFailureReason::EmptyBody),
            default => ProxyOutcome::success(),
        };
    }

    private function connectionFailureReason(ConnectionException $exception): ProxyFailureReason
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'SSL') => ProxyFailureReason::TlsError,
            str_contains($message, 'timed out') || str_contains($message, 'Timeout') => ProxyFailureReason::NetworkTimeout,
            default => ProxyFailureReason::ConnectFailed,
        };
    }

    private function canonicalHost(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function recordFailure(Document $document, \Throwable $exception): void
    {
        $attempts = $document->attempts + 1;
        $maxAttempts = (int) config('crawler.documents_max_attempts', 5);
        $backoffMinutes = (int) config('crawler.documents_retry_backoff_minutes', 15);
        $failedPermanently = $attempts >= $maxAttempts;

        $this->storeRepository->update($document, [
            'attempts' => $attempts,
            'last_error' => $exception->getMessage(),
            'last_error_at' => now(),
            'next_retry_at' => $failedPermanently ? null : now()->addMinutes($backoffMinutes * $attempts),
            'failed_permanently' => $failedPermanently,
        ]);
    }

    private function store(string $hash, string $content): string
    {
        $disk = config('crawler.documents_disk', config('crawler.raw_disk'));
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $content);
        }

        return $path;
    }

    private function filenameFrom(Document $document): string
    {
        return $document->title !== '' ? $document->title : "document-{$document->id}";
    }

    private function extensionFrom(Document $document): string
    {
        $extension = pathinfo($this->filenameFrom($document), PATHINFO_EXTENSION);

        return $extension !== '' ? $extension : 'bin';
    }

    private function mimeFrom(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
