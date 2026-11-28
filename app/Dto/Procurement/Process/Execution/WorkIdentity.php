<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;

/** Canonical identity; only an explicit manual retry adds a durable intent and predecessor. */
final readonly class WorkIdentity
{
    public function __construct(
        public WorkInput $input,
        public ?ProcessUuid $manualRetryIntentId = null,
        public ?WorkId $retryOfWorkId = null,
    ) {
        if (($manualRetryIntentId === null) !== ($retryOfWorkId === null)) {
            throw new \InvalidArgumentException('Manual retry identity requires both intent and predecessor.');
        }
    }

    public function key(): ContentHash
    {
        return ContentHash::of('work-identity:v1:'.$this->payload()->json);
    }

    /** Diagnostic components and key use the same canonical boundary; ordinary identities stay byte-identical. */
    public function payload(): VersionedPayload
    {
        $payload = (new ProcessPayloadCodec)->encodeInput($this->input);
        if ($this->manualRetryIntentId === null) {
            return $payload;
        }

        return new VersionedPayload(json_encode([
            'input' => json_decode($payload->json ?? throw new \LogicException('Work identity requires input.'), true, flags: JSON_THROW_ON_ERROR),
            'input_schema_version' => $payload->schemaVersion,
            'manual_retry' => ['intent_id' => $this->manualRetryIntentId->value, 'retry_of_work_id' => $this->retryOfWorkId?->value->value],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1);
    }
}
