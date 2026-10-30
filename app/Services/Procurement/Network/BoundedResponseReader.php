<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Limits apply to decoded bytes (Guzzle decode_content), never only Content-Length. */
final readonly class BoundedResponseReader
{
    public static function assertTime(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            throw OutboundRequestDeadlineExceeded::forStage(stage: 'response-body');
        }
    }

    public static function assertComplete(StreamInterface $input): void
    {
        if (! $input->eof()) {
            throw new RuntimeException('Response body stalled before EOF.');
        }
    }

    public function read(Response $response, int $maxBytes, ?float $deadlineAt): string
    {
        $body = '';
        $input = $response->toPsrResponse()->getBody();

        try {
            while (! $input->eof()) {
                self::assertTime(deadlineAt: $deadlineAt);
                $chunk = $input->read(min(8192, $maxBytes - strlen($body) + 1));
                if ($chunk === '') {
                    BoundedResponseReader::assertComplete($input);
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    throw new SourceResponseLimitExceeded("Source body exceeds {$maxBytes} decoded bytes.");
                }
            }
            self::assertTime(deadlineAt: $deadlineAt);

            return $body;
        } finally {
            $response->close();
        }
    }
}
