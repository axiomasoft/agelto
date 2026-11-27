<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Execution\StageQueueMap;

test('AI and preparation delivery bounds come from the same boot validated StageQueueMap', function (): void {
    $map = app(StageQueueMap::class);
    $prepare = $map->forStage(StageCode::Prepare);
    expect($prepare->jobTimeout)->toBe(260)->and($prepare->connection)->toBe('process_long')
        ->and($prepare->queue)->toBe('process-prepare')->and($prepare->leaseSeconds)->toBe(360);
    foreach ([StageCode::Screening, StageCode::Extraction] as $stage) {
        $lane = $map->forStage($stage);
        expect($lane->jobTimeout)->toBeLessThan($lane->leaseSeconds);
    }
});
