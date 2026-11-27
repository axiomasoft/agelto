<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Storage;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;

final readonly class StorageReservationRequest
{
    public function __construct(
        public ProcessUuid $id,
        public ProcessUuid $domainId,
        public ProcessUuid $ownerToken,
        public int $generation,
        public int $byteUpperBound,
        public StoragePhase $phase,
        public string $disk,
        public ?ProcessUuid $workId = null,
        public ?ProcessUuid $attemptId = null,
        public ?ProcessUuid $operationId = null,
    ) {
        if ($generation < 1 || $byteUpperBound < 1 || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $disk) !== 1
            || (($workId === null) !== ($attemptId === null)) || ($workId !== null && $operationId !== null)) {
            throw new \InvalidArgumentException('Invalid storage reservation identity or bound.');
        }
    }

    public function objectKey(): string
    {
        return 'documents/uploads/'.$this->id->value.'/object';
    }
}
