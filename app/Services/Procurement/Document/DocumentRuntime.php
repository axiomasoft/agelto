<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Document\DownloadFinalizer;
use App\Repositories\Procurement\Document\PrepareFinalizer;
use App\Services\Procurement\Process\StageRegistry;

/** Pure composition; explicit catalog sync, never boot, installs DB authorities. */
final readonly class DocumentRuntime
{
    public const string VERSION = 'documents/1';

    public function register(StageRegistry $registry): void
    {
        $registry->register(StageCode::Download, self::VERSION);
        $registry->bindFinalization(StageCode::Download, new DownloadStageCodec, app(DownloadFinalizer::class));
        $registry->bindHandler(StageCode::Download, app(RuntimeDownloadHandler::class));
        $registry->register(StageCode::Prepare, self::VERSION);
        $registry->bindFinalization(StageCode::Prepare, new PrepareStageCodec, app(PrepareFinalizer::class));
        $registry->bindHandler(StageCode::Prepare, app(PrepareHandler::class));
    }
}
