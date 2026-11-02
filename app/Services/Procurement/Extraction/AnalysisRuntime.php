<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Ai\PublicationRepository;
use App\Repositories\Procurement\Ai\ScreeningFinalizer;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Screening\ScreeningHandler;
use App\Services\Procurement\Screening\ScreeningStageCodec;

/** Pure composition. Activation is wired with the coherent consumer/schema cutover. */
final class AnalysisRuntime
{
    public const string VERSION = 'analysis/1';

    public function register(StageRegistry $registry): void
    {
        $registry->register(StageCode::Screening, self::VERSION);
        $registry->bindFinalization(StageCode::Screening, new ScreeningStageCodec, app(ScreeningFinalizer::class));
        $registry->bindHandler(StageCode::Screening, app(ScreeningHandler::class));
        $registry->register(StageCode::Extraction, self::VERSION);
        $registry->bindFinalization(StageCode::Extraction, new ExtractionStageCodec, app(ChunkCommitter::class));
        $registry->bindHandler(StageCode::Extraction, app(ExtractionHandler::class));
        $registry->register(StageCode::Publication, self::VERSION);
        $registry->bindFinalization(StageCode::Publication, new PublicationStageCodec, app(PublicationRepository::class));
        $registry->bindHandler(StageCode::Publication, app(PublicationHandler::class));
    }
}
