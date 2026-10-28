<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\TransferBudget;
use App\Exceptions\Procurement\Network\ResponseLimitExceeded;
use Closure;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** Disk-backed decompressed sink. Rejects a chunk BEFORE writing beyond the entity limit. */
final class BoundedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $bytes = 0;

    public function __construct(private readonly int $limit, private readonly Closure $tick, private readonly TransferBudget $budget)
    {
        $file = tmpfile();
        if ($file === false) {
            throw new \RuntimeException('Cannot allocate outbound spool.');
        }
        $this->stream = new Stream($file);
    }

    public function write(string $string): int
    {
        ($this->tick)();
        if (strlen($string) > $this->limit - $this->bytes) {
            throw new ResponseLimitExceeded('Entity byte limit exceeded.');
        }
        $this->budget->entity(strlen($string));
        $written = $this->stream->write($string);
        $this->bytes += $written;

        return $written;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }
}
