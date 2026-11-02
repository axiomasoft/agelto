<?php

declare(strict_types=1);

use App\Services\Procurement\Extraction\PublicationHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\PublicationRuntimeFixtures;

test('canonical publication preserves model uncertainty on exactly its source anchored item', function (): void {
    $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $raw['rows'][1]['row']['quality'] = ['reason' => 'unreadable', 'fields' => ['quantity', 'total_price_curr']];
    [$work,$lease,$input] = PublicationRuntimeFixtures::ready(json_encode($raw, JSON_THROW_ON_ERROR));
    app(WorkFinalizer::class)->finish($lease, app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $marked = DB::table('prc_estimate_items')->whereNotNull('quality')->sole();
    expect($marked->row_index)->toBe(1)
        ->and(json_decode($marked->quality, true, flags: JSON_THROW_ON_ERROR))->toEqual(['model' => ['reason' => 'unreadable', 'fields' => ['quantity', 'total_price_curr']]])
        ->and(DB::table('prc_estimate_items')->whereNull('quality')->count())->toBe(1);
});

test('canonical publication writes SQL NULL for clean quality so the marked filter remains exact', function (): void {
    [$work,$lease,$input] = PublicationRuntimeFixtures::ready();
    app(WorkFinalizer::class)->finish($lease, app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(DB::table('prc_estimate_items')->count())->toBe(2)
        ->and(DB::table('prc_estimate_items')->whereNotNull('quality')->count())->toBe(0);
});
