<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class DetailProgress implements StageCheckpoint
{
    /** @param array<string, ArtifactRef> $parts */
    public function __construct(public array $parts = [], public int $pass = 1, public int $failures = 0,
        public ?SnapshotConsistency $consistency = null, public ?string $rulesHash = null)
    {
        if (count($parts) > 8 || $pass < 1 || $pass > 3 || $failures < 0 || $failures > 3) {
            throw new \InvalidArgumentException('Invalid detail progress bounds.');
        }
        self::validateParts($parts);
        if ($consistency !== null && $consistency !== SnapshotConsistency::Unstable) {
            throw new \InvalidArgumentException('Only unstable evidence belongs to incomplete progress.');
        }
        if ($rulesHash !== null && preg_match('/^[0-9a-f]{64}$/D', $rulesHash) !== 1) {
            throw new \InvalidArgumentException('Invalid detail rules hash.');
        }
    }

    /** @param array<mixed> $parts */
    private static function validateParts(array $parts): void
    {
        foreach ($parts as $name => $ref) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || ! $ref instanceof ArtifactRef) {
                throw new \InvalidArgumentException('Invalid detail part reference.');
            }
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Detail;
    }
}
