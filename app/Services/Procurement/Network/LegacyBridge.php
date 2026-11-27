<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundOperationHandle;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Closure;

/**
 * Bounded legacy_bridge owner: ровно одна outbound operation на logical call
 * текущего caller без ProcessAttempt. Открывается непосредственно перед call, закрывается в
 * finally; crash оставляет open row, которую reaper CAS-ит в expired. Это не второй workflow:
 * нет retries, очередей, domain state или авто-оживления — business scheduler/leases остаются у
 * существующего caller.
 */
final readonly class LegacyBridge
{
    public function __construct(
        private OutboundOperationRepository $operations,
        private OutboundPolicy $policy,
        private ProcessTransaction $transaction,
    ) {}

    /**
     * @template T
     *
     * @param  list<string>  $controlScopeKeys
     * @param  Closure(OutboundExecutionContext): T  $call
     * @param  Closure(): void|null  $checkpoint
     * @return T
     */
    public function run(
        string $callerCode,
        ?int $platformId,
        array $controlScopeKeys,
        int $deadlineSeconds,
        ContentHash $policyHash,
        Closure $call,
        ?Closure $checkpoint = null,
        ?ProviderKey $providerKey = null,
        OutboundOperationKind $kind = OutboundOperationKind::LegacyBridge,
    ): mixed {
        if ($deadlineSeconds < 1 || $deadlineSeconds > $this->policy->bridgeMaxDeadlineSeconds) {
            throw new \InvalidArgumentException('Legacy bridge deadline must be within 1..outbound.legacy_bridge.max_deadline_seconds.');
        }
        $checkpoint?->__invoke();
        [$id, $token] = [ProcessUuid::random(), ProcessUuid::random()];
        $handle = $this->transaction->run(fn (): OutboundOperationHandle => $this->operations->open(
            $id, $token, $kind, $platformId, $callerCode, $policyHash,
            $controlScopeKeys, min($this->policy->bridgeLeaseSeconds, $deadlineSeconds), $deadlineSeconds,
            probeToken: $kind === OutboundOperationKind::Probe ? $token : null, providerKey: $providerKey,
        ));
        try {
            $context = new OutboundExecutionContext($handle, $this->renew(...), $checkpoint, $deadlineSeconds, max(1, min(15, intdiv($this->policy->bridgeLeaseSeconds, 3))));
            $context->tick();

            return $call($context);
        } finally {
            try {
                $this->transaction->run(fn (): bool => $this->operations->close($handle));
            } catch (\Throwable $exception) {
                // Close не маскирует исход call: незакрытую row позже expire-ит reaper по DB lease.
                report($exception);
            }
        }
    }

    /** Продление lease живой operation; null — owner потерян, новый send запрещён. */
    public function renew(OutboundOperationHandle $handle): ?OutboundOperationHandle
    {
        return $this->transaction->run(fn (): ?OutboundOperationHandle => $this->operations->renew($handle, $this->policy->bridgeLeaseSeconds));
    }

    /** Bounded crash cleanup batch для reconcile daemon. */
    public function expireStale(int $limit = 100): int
    {
        return $this->transaction->run(fn (): int => $this->operations->expireStale($limit));
    }
}
