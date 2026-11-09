<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;

/** Platform-prepared immutable allocation. No transport or admission occurs while allocating. */
final readonly class AcquisitionLaunch
{
    public function __construct(
        public int $profileId,
        public int $profileVersion,
        public int $platformId,
        public int $registryId,
        public string $registry,
        public AcquisitionKind $kind,
        public string $adapterVersion,
        public CursorEnvelope $filter,
        public ContentHash $accessPolicyHash,
        public ContentHash $streamKey,
        public ListingPlan $plan,
        public VersionedPayload $configuration,
    ) {
        if (min($profileId, $profileVersion, $platformId, $registryId) < 1 || $registry === ''
            || $adapterVersion === '' || $configuration->json === null) {
            throw new \InvalidArgumentException('Invalid acquisition launch.');
        }
        if (in_array($configuration->schemaVersion, [2, 3], true)) {
            $settings = AcquisitionConfiguration::decode($configuration);
            // Recovery keeps the original execution settings while the allocation CAS
            // checks the current profile revision independently.
            if ($settings->kind !== $kind) {
                throw new \InvalidArgumentException('Acquisition configuration does not match the prepared profile.');
            }
        }
    }
}
