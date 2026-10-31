<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Crawler\DetailFinalizer;
use App\Repositories\Procurement\Crawler\ListingFinalizer;
use App\Repositories\Procurement\Crawler\StatusFinalizer;
use App\Repositories\Procurement\Crawler\ValidatedAcquisitionFinalizer;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\StageRegistry;

/** Pure composition. Catalog synchronization explicitly installs the matching handler authority. */
final readonly class AcquisitionRuntime
{
    public const string VERSION = 'acquisition/1';

    public function __construct(private PlatformRegistry $platforms) {}

    public function values(): DetailValueCodec
    {
        $types = [];
        foreach ($this->platforms->all() as $definition) {
            if ($definition->extension !== null) {
                $type = $definition->extension->dto;
                if (! class_exists($type)) {
                    throw new \LogicException('Missing registered extension type.');
                }
                $types[] = $type;
            }
        }

        return new DetailValueCodec(array_values(array_unique($types)));
    }

    public function register(StageRegistry $registry, DetailValueCodec $values): void
    {
        foreach ([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh] as $stage) {
            $registry->register($stage, self::VERSION);
            [$codec, $finalizer] = match ($stage) {
                StageCode::Listing => [new ListingStageCodec($values), app(ListingFinalizer::class)],
                StageCode::Detail => [new DetailStageCodec($values), app(DetailFinalizer::class)],
                StageCode::StatusRefresh => [new StatusStageCodec($values), app(StatusFinalizer::class)],
            };
            $registry->bindFinalization($stage, new ValidatedAcquisitionCodec($codec),
                new ValidatedAcquisitionFinalizer($finalizer, app(AdapterHealthRepository::class)));
            $registry->bindHandler($stage, app()->makeWith(AcquisitionHandler::class, ['stage' => $stage]));
        }
    }
}
