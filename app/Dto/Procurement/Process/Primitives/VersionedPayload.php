<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

/** JSON is confined to this persistence boundary, with an all-or-none schema pair. */
final readonly class VersionedPayload
{
    public function __construct(public ?string $json, public ?int $schemaVersion)
    {
        if (($json === null) !== ($schemaVersion === null)) {
            throw new \InvalidArgumentException('Payload and schema version must both be null or present.');
        }
        if ($schemaVersion !== null) {
            ProcessValue::positive($schemaVersion);
        }
    }
}
