<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\TransportFailure;

final readonly class HttpAttemptResult
{
    public ?HttpResponseHead $head;

    public function __construct(
        public ?ResponseEnvelope $response,
        public ?TransportFailure $failure,
        public int $wireBytes,
        public int $entityBytes,
        public int $curlCode = 0,
        ?HttpResponseHead $head = null,
    ) {
        if (($response === null) === ($failure === null) || min($wireBytes, $entityBytes) < 0) {
            throw new \InvalidArgumentException('A physical attempt has exactly one result.');
        }
        $this->head = $response === null ? $head : new HttpResponseHead($response->status, $response->headers);
    }
}
