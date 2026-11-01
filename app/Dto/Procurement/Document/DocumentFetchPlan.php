<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Network\TrafficClass;

/** Source hook only describes how to obtain bytes. No persistence or execution ownership. */
final readonly class DocumentFetchPlan
{
    public function __construct(public OutboundRequestPlan $request, public string $filename,
        public ?ContentHash $expectedHash = null, public ?int $expectedBytes = null)
    {
        if ($filename === '' || strlen($filename) > 1024 || ($expectedBytes !== null && $expectedBytes < 1)
            || $request->method !== 'GET' || $request->idempotency !== IdempotencyClass::ReadOnly
            || $request->trafficClass !== TrafficClass::Download || $request->profile->responseKind !== ResponseKind::Binary
            || $request->profile->totalSeconds > 240) {
            throw new \InvalidArgumentException('Invalid bounded document fetch plan.');
        }
    }
}
