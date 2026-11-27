<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationClaim;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;

/**
 * Typed outbound request: target, method, safe URL, endpoint profile,
 * traffic class и idempotency. physicalRequestId создаётся ДО DB retry и равен demand
 * physical_request_id; каждый retry/redirect hop получает новый id. Secrets не входят в план —
 * transport разрешает их поздно по secret ref route.
 */
final readonly class OutboundRequestPlan
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        public ProcessUuid $physicalRequestId,
        public ProcessUuid $targetId,
        public string $method,
        public SafeUrl $url,
        public RequestProfile $profile,
        public TrafficClass $trafficClass,
        public IdempotencyClass $idempotency,
        public ?ContentHash $bodyHash = null,
        public ?int $sendLimit = null,
        public ?AdapterValidationClaim $adapterClaim = null,
    ) {
        if (! in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported outbound method.');
        }
        if (in_array($method, ['GET', 'HEAD'], true) && $bodyHash !== null) {
            throw new \InvalidArgumentException('Read methods cannot carry a body hash.');
        }
        if ($sendLimit !== null && ($sendLimit < 1 || $sendLimit > $profile->attempts * ($profile->redirects + 1))) {
            throw new \InvalidArgumentException('Caller send bound exceeds the endpoint policy.');
        }
        if ($method === 'POST' && $idempotency === IdempotencyClass::ReadOnly && $bodyHash === null) {
            throw new \InvalidArgumentException('A read-only POST must pin its body hash.');
        }

    }

    /** Максимум physical sends этого logical request: ambiguous write никогда не повторяется. */
    public function maxAttempts(): int
    {
        return $this->idempotency->allowsTransportRetry() ? $this->profile->attempts : 1;
    }
}
