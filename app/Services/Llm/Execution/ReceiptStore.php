<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Llm\ReceiptUnavailable;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** One bounded v1 response envelope at the pre-authorized key. No object I/O under DB locks. */
final readonly class ReceiptStore
{
    public const int MAX_BYTES = 16_777_216;

    // JSON can expand each body byte to a six-byte escape (for example, \u0000).
    // Keep the accepted response bound unchanged while allowing its complete v1 envelope.
    private const int MAX_ENVELOPE_BYTES = self::MAX_BYTES * 6;

    public function __construct(private ReceiptPolicies $policies) {}

    public function put(ProviderCall $call, int $status, string $body): VerifiedReceipt
    {
        $this->outsideTransaction();
        if (! $call->claimed || $status < 100 || $status > 599 || strlen($body) > self::MAX_BYTES - 4096) {
            throw new OperationDenied('invalid_receipt_capture');
        }
        $bytes = CanonicalJson::encode(['version' => 1, 'physical_call_id' => $call->authorization->physicalCallId,
            'logical_request_id' => $call->authorization->logicalRequestId, 'request_epoch' => $call->authorization->requestEpoch,
            'send_authorization_token' => $call->authorization->sendAuthorizationToken, 'purpose' => $call->purpose->value,
            'status' => $status, 'body' => $body]);
        $disk = Storage::disk($call->receiptDisk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new ReceiptUnavailable('Receipt disk requires atomic create-if-absent support.');
        }
        $key = $call->receiptUploadKey;
        $temporary = null;
        try {
            if (! $disk->makeDirectory(dirname($key))) {
                throw new ReceiptUnavailable('Receipt directory unavailable.');
            }
            $path = $disk->path($key);
            if (! is_file($path)) {
                $temporary = tempnam(dirname($path), '.receipt-');
                if ($temporary === false) {
                    throw new ReceiptUnavailable('Receipt spool unavailable.');
                }
                $stream = fopen($temporary, 'wb');
                if ($stream === false) {
                    throw new ReceiptUnavailable('Receipt spool unavailable.');
                }
                try {
                    if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                        throw new ReceiptUnavailable('Receipt durable write unconfirmed.');
                    }
                } finally {
                    fclose($stream);
                }
                // Atomic visibility only after the complete object. A competing capture cannot overwrite.
                if (! @link($temporary, $path) && ! is_file($path)) {
                    throw new ReceiptUnavailable('Receipt immutable publish failed.');
                }
            }
            $this->syncDurablePath($disk->path(''), $path);
            $verified = $this->inspect($call) ?? throw new ReceiptUnavailable('Receipt missing after put.');
            if ($verified->hash !== hash('sha256', $bytes)) {
                throw new OperationDenied('immutable_receipt_conflict');
            }

            return $verified;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function inspect(ProviderCall $call): ?VerifiedReceipt
    {
        $this->outsideTransaction();
        try {
            $disk = Storage::disk($call->receiptDisk);
            if (! $disk->exists($call->receiptUploadKey)) {
                if ($call->receiptHash !== null) {
                    throw new ReceiptUnavailable('Pinned receipt object missing.');
                }

                return null;
            }
            if ($disk->size($call->receiptUploadKey) > self::MAX_ENVELOPE_BYTES) {
                throw new ReceiptUnavailable('Receipt exceeds bounded codec.');
            }
            $bytes = $disk->get($call->receiptUploadKey);
            if (! is_string($bytes)) {
                throw new ReceiptUnavailable('Receipt read unavailable.');
            }
            if ($call->receiptHash === null && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
                $this->syncDurablePath($disk->path(''), $disk->path($call->receiptUploadKey));
            }
        } catch (ReceiptUnavailable $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new ReceiptUnavailable('Receipt storage unavailable.', previous: $failure);
        }
        try {
            $value = json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value) || CanonicalJson::encode($value) !== $bytes || ($value['version'] ?? null) !== 1
                || ($value['physical_call_id'] ?? null) !== $call->authorization->physicalCallId
                || ($value['logical_request_id'] ?? null) !== $call->authorization->logicalRequestId
                || ($value['request_epoch'] ?? null) !== $call->authorization->requestEpoch
                || ($value['send_authorization_token'] ?? null) !== $call->authorization->sendAuthorizationToken
                || ($value['purpose'] ?? null) !== $call->purpose->value || ! is_int($value['status'] ?? null) || ! is_string($value['body'] ?? null)
                || count($value) !== 8 || $value['status'] < 100 || $value['status'] > 599
                || strlen($value['body']) > self::MAX_BYTES - 4096 || ! $call->claimed) {
                throw new ReceiptUnavailable('Receipt identity/codec mismatch.');
            }
            $hash = hash('sha256', $bytes);
            if ($call->receiptHash !== null && $call->receiptHash !== $hash) {
                throw new ReceiptUnavailable('Pinned receipt hash mismatch.');
            }

            return new VerifiedReceipt($call, $hash, $value['status'], $value['body'], $call->recordedAssessment
                ?? $this->policies->assess($call->context->provider, $call->purpose, $value['status'], $value['body']));
        } catch (\JsonException $failure) {
            throw new ReceiptUnavailable('Partial receipt is not evidence.', previous: $failure);
        }
    }

    private function outsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Receipt object I/O is forbidden inside DB transactions.');
        }
    }

    private function syncDurablePath(string $rootPath, string $filePath): void
    {
        $fileStream = @fopen($filePath, 'rb');
        if (! is_resource($fileStream)) {
            throw new ReceiptUnavailable('Receipt file durability sync unavailable.');
        }
        try {
            if (! @fsync($fileStream)) {
                throw new ReceiptUnavailable('Receipt file durability sync unconfirmed.');
            }
        } finally {
            fclose($fileStream);
        }

        $root = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $directory = dirname($filePath);
        while ($root === '' || str_starts_with($directory, $root)) {
            $dirStream = @fopen($directory, 'rb');
            if (! is_resource($dirStream)) {
                throw new ReceiptUnavailable('Receipt directory durability sync unavailable.');
            }
            try {
                if (! @fsync($dirStream)) {
                    throw new ReceiptUnavailable('Receipt directory durability sync unconfirmed.');
                }
            } finally {
                fclose($dirStream);
            }
            if ($directory === $root || dirname($directory) === $directory) {
                break;
            }
            $directory = dirname($directory);
        }
    }
}
