<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\EventCode;
use App\Enums\Procurement\Process\StageCode;

/**
 * Typed envelope accepted transition, единственная форма, в которой событие
 * покидает DB transaction. Только IDs, закрытые коды и время: ни URL, ни payload, ни текст ошибки,
 * ни owner token. reason — bounded namespaced snake_case код зарегистрированной schema.
 */
final readonly class ProcessEventEnvelope
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public ProcessUuid $eventId,
        public EventCode $event,
        public ?ProcessUuid $runId,
        public WorkId $workId,
        public ProcessUuid $attemptId,
        public StageCode $stage,
        public ?string $platformCode,
        public ?string $reason,
        public ?\DateTimeImmutable $retryAt,
        public bool $itemFailureConsumed,
        public ?string $traceId,
        public \DateTimeImmutable $occurredAt,
    ) {
        if ($reason !== null && preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('reason must be a bounded snake_case code.');
        }
        if ($traceId !== null && preg_match('/^[0-9a-f]{32}$/D', $traceId) !== 1) {
            throw new \InvalidArgumentException('trace_id must be 32 lowercase hex characters.');
        }
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        $utc = new \DateTimeZone('UTC');

        return array_filter([
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $this->eventId->value,
            'event' => $this->event->value,
            'run_id' => $this->runId?->value,
            'work_id' => $this->workId->value->value,
            'attempt_id' => $this->attemptId->value,
            'stage' => $this->stage->value,
            'platform_code' => $this->platformCode,
            'reason' => $this->reason,
            'retry_at' => $this->retryAt?->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'item_failure_consumed' => $this->itemFailureConsumed,
            'trace_id' => $this->traceId,
            'occurred_at' => $this->occurredAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ], static fn (bool|int|string|null $value): bool => $value !== null);
    }
}
