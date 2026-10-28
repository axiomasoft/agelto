<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Closure;
use Illuminate\Support\Str;

/**
 * Bounded legacy_bridge owner (bp-ddl-network): ровно одна outbound operation на logical call
 * текущего caller без ProcessAttempt. Открывается непосредственно перед call, закрывается в
 * finally; crash оставляет open row, которую reaper CAS-ит в expired. Это не второй workflow:
 * нет retries, очередей, domain state или авто-оживления — business scheduler/leases остаются у
 * существующего owner до P2.14–P2.19/P3.1–P3.6. Callers подключает atomic cutover P2.6.
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
    ): mixed {
        if ($deadlineSeconds < 1 || $deadlineSeconds > $this->policy->bridgeMaxDeadlineSeconds) {
            throw new \InvalidArgumentException('Legacy bridge deadline must be within 1..outbound.legacy_bridge.max_deadline_seconds.');
        }
        $checkpoint?->__invoke();
        [$id, $token] = [self::uuid(), self::uuid()];
        $handle = $this->transaction->run(fn (): OutboundOperationHandle => $this->operations->open(
            $id, $token, OutboundOperationKind::LegacyBridge, $platformId, $callerCode, $policyHash,
            $controlScopeKeys, min($this->policy->bridgeLeaseSeconds, $deadlineSeconds), $deadlineSeconds,
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

    /** Bounded crash cleanup batch; вызывает reconcile daemon после activation P2.6. */
    public function expireStale(int $limit = 100): int
    {
        return $this->transaction->run(fn (): int => $this->operations->expireStale($limit));
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
