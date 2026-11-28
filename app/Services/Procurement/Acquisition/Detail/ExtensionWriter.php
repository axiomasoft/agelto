<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Services\Procurement\Platform\Contracts\ExtensionPersister;
use App\Services\Procurement\Platform\PlatformRegistry;

final readonly class ExtensionWriter
{
    public function __construct(private PlatformRegistry $platforms) {}

    public function validate(PlatformCode $platform, ?object $extension): void
    {
        if ($extension !== null && $this->platforms->get($platform)->extension?->dto !== $extension::class) {
            throw new \InvalidArgumentException('Unexpected platform extension type.');
        }
    }

    public function persist(PlatformCode $platform, int $procurementId, ?object $extension): void
    {
        CallerTransaction::require('Platform extension publication');
        $this->validate($platform, $extension);
        if ($extension === null) {
            return;
        }
        $descriptor = $this->platforms->get($platform)->extension ?? throw new \LogicException('Missing extension descriptor.');
        $writer = app($descriptor->persister);
        if (! $writer instanceof ExtensionPersister) {
            throw new \LogicException('Invalid extension writer.');
        }
        $writer->persist($procurementId, $extension);
    }
}
