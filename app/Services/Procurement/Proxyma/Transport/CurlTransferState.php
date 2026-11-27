<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\ResponseReader;

/** @internal Mutable per-transfer state shared across cURL callbacks without by-ref closure captures. */
final class CurlTransferState
{
    public ?ResponseReader $reader = null;

    /** @var array<string, list<string>> */
    public array $headers = [];

    public int $status = 0;

    public int $headerBytes = 0;

    public bool $limit = false;

    public int $lastActivity;

    /** @var array{0: int, 1: int} */
    public array $progress = [0, 0];

    public ?TransportFailure $failure = null;

    public ?\Throwable $readerError = null;

    public function __construct(
        private readonly ResponseProfile $responseProfile,
        private readonly ConnectionScope $scope,
        private readonly int $maxResponseBytes,
    ) {
        $this->lastActivity = hrtime(true);
    }

    public function writeChunk(string $chunk): int
    {
        $this->lastActivity = hrtime(true);
        try {
            $this->reader ??= new ResponseReader($this->responseProfile, $this->scope, $this->headers['content-encoding'][0] ?? '');
            $this->reader->write($chunk);
            if ($this->reader->wireBytes() > $this->maxResponseBytes) {
                throw new \LengthException('Physical response byte limit exceeded.');
            }
        } catch (\Throwable $error) {
            $this->limit = $error instanceof \LengthException;
            $this->readerError = $error;

            return 0;
        }

        return strlen($chunk);
    }

    public function writeHeader(string $line): int
    {
        $this->lastActivity = hrtime(true);
        $this->headerBytes += strlen($line);
        if ($this->headerBytes > 65_536) {
            $this->limit = true;

            return 0;
        }
        if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $line, $match)) {
            $this->status = (int) $match[1];
            $this->headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if (in_array($name, ['content-type', 'content-length', 'content-encoding', 'content-disposition', 'etag', 'last-modified', 'location', 'retry-after'], true)) {
                $this->headers[$name][] = trim($value);
            }
        }

        return strlen($line);
    }

    public function recordProgress(int $downloaded, int $uploaded): int
    {
        if ($this->progress !== [$downloaded, $uploaded]) {
            $this->progress = [$downloaded, $uploaded];
            $this->lastActivity = hrtime(true);
        }

        return 0;
    }

    public function ensureReader(): ResponseReader
    {
        return $this->reader ??= new ResponseReader($this->responseProfile, $this->scope, $this->headers['content-encoding'][0] ?? '');
    }
}
