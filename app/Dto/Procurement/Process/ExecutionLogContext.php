<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

/**
 * Корреляция одного attempt (observability#s02): IDs восстанавливаются из БД при claim, не из
 * Redis. trace_id — диагностический (32 lowercase hex), новый на каждый attempt; не idempotency key.
 */
final readonly class ExecutionLogContext
{
    public function __construct(
        public ?ProcessUuid $runId,
        public WorkId $workId,
        public ProcessUuid $attemptId,
        public StageCode $stage,
        public ?string $platformCode,
        public string $traceId,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $traceId) !== 1) {
            throw new \InvalidArgumentException('trace_id must be 32 lowercase hex characters.');
        }
        if ($platformCode !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platformCode) !== 1) {
            throw new \InvalidArgumentException('platform_code must be a bounded lowercase code.');
        }
    }

    public static function forLease(ExecutionLease $lease, ?ProcessUuid $runId, ?string $platformCode): self
    {
        return new self($runId, $lease->workId, $lease->attemptId, $lease->stage, $platformCode, bin2hex(random_bytes(16)));
    }

    /** @return array<string, string> Только непустые поля; значения — IDs и закрытые коды. */
    public function toContext(): array
    {
        return array_filter([
            'run_id' => $this->runId?->value,
            'work_id' => $this->workId->value->value,
            'attempt_id' => $this->attemptId->value,
            'stage' => $this->stage->value,
            'platform_code' => $this->platformCode,
            'trace_id' => $this->traceId,
        ], static fn (?string $value): bool => $value !== null);
    }
}
