<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisWindowPlan;
use App\Dto\Procurement\Ai\CoverageStrategy;
use App\Dto\Procurement\Ai\RowRange;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Services\Procurement\Extraction\ChunkStitcher;
use App\Services\Procurement\Extraction\WindowPlanner;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ChunkAssemblyFixtures;

pest()->group('structured-extraction-contract', 'analysis-execution');

test('window IDs and exact half-open source coverage are pinned to the immutable run', function (): void {
    $recipe = AiFixtures::recipe();
    $recipe = new AnalysisRecipe($recipe->preparationHash, $recipe->analyzerVersion, $recipe->tasks, $recipe->llm,
        new CoverageStrategy(3, 1), $recipe->currency, $recipe->locale);
    $run = ChunkAssemblyFixtures::run($recipe);
    $planner = new WindowPlanner;
    $plan = $planner->plan($run, 1, new RowRange(0, 6), ChunkAssemblyFixtures::anchors(6));
    expect(array_map(fn ($w) => $w->range, $plan->windows))->toEqual([new RowRange(0, 3), new RowRange(2, 5), new RowRange(4, 6)])
        ->and($planner->plan($run, 1, new RowRange(0, 6), ChunkAssemblyFixtures::anchors(6)))->toEqual($plan)
        ->and(fn () => new AnalysisWindowPlan($run, 1, new RowRange(0, 6), [$plan->windows[0], $plan->windows[2]]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AnalysisWindowPlan($run, 1, new RowRange(0, 7), $plan->windows))->toThrow(InvalidArgumentException::class);
});

test('stitching deduplicates matching overlap by estimate and anchor and preserves depth five totals and chunk provenance', function (): void {
    $recipe = AiFixtures::recipe();
    $recipe = new AnalysisRecipe($recipe->preparationHash, $recipe->analyzerVersion, $recipe->tasks, $recipe->llm,
        new CoverageStrategy(3, 1), $recipe->currency, $recipe->locale);
    $plan = (new WindowPlanner)->plan(ChunkAssemblyFixtures::run($recipe), 1, new RowRange(0, 6), ChunkAssemblyFixtures::anchors(6));
    $chunks = array_map(fn ($w) => ChunkAssemblyFixtures::chunk($w), $plan->windows);
    $result = (new ChunkStitcher)->stitch($plan, array_reverse($chunks));
    expect(array_map(fn ($r) => $r->row->row_index, $result->rows))->toBe(range(0, 5))
        ->and($result->totals)->toHaveCount(6)
        ->and($result->totals[5]->parent_id)->toBe('n4')
        ->and($result->rows[0]->row->unit_price_curr)->toBe('9007199254740993.01')
        ->and($result->rowChunks['p0:r2'])->toHaveCount(2)
        ->and($result->totalChunks['n5'])->toHaveCount(3);
});

test('stitching rejects conflicting overlap instead of silently keeping the first row or total', function (string $conflict): void {
    $recipe = AiFixtures::recipe();
    $recipe = new AnalysisRecipe($recipe->preparationHash, $recipe->analyzerVersion, $recipe->tasks, $recipe->llm,
        new CoverageStrategy(2, 1), $recipe->currency, $recipe->locale);
    $plan = (new WindowPlanner)->plan(ChunkAssemblyFixtures::run($recipe), 1, new RowRange(0, 3), ChunkAssemblyFixtures::anchors(3));
    $first = ChunkAssemblyFixtures::chunk($plan->windows[0]);
    $second = ChunkAssemblyFixtures::chunk($plan->windows[1], name: $conflict === 'row' ? 'Different source claim' : null,
        total: $conflict === 'total' ? '1.00' : null);
    expect(fn () => (new ChunkStitcher)->stitch($plan, [$first, $second]))->toThrow(PublicationBlocked::class, 'chunk_conflict');
})->with(['row', 'total']);

test('a missing or duplicate subrange or foreign run never becomes complete publication', function (string $defect): void {
    $plan = (new WindowPlanner)->plan(ChunkAssemblyFixtures::run(), 1, new RowRange(0, 4), ChunkAssemblyFixtures::anchors(4));
    $left = ChunkAssemblyFixtures::chunk($plan->windows[0], new RowRange(0, 2));
    $right = ChunkAssemblyFixtures::chunk($plan->windows[0], new RowRange(2, 4));
    $chunks = match ($defect) {
        'missing' => [$left],
        'duplicate' => [$left, $left, $right],
        'overlap' => [$left, ChunkAssemblyFixtures::chunk($plan->windows[0], new RowRange(1, 4))],
        'foreign' => [$left, ChunkAssemblyFixtures::chunk((new WindowPlanner)->plan(ChunkAssemblyFixtures::run(), 1,
            new RowRange(2, 4), ChunkAssemblyFixtures::anchors(4))->windows[0])],
    };
    expect(fn () => (new ChunkStitcher)->stitch($plan, $chunks))->toThrow(PublicationBlocked::class);
})->with(['missing', 'duplicate', 'overlap', 'foreign']);

test('split chunks restore the entire parent and keep exact row provenance', function (): void {
    $plan = (new WindowPlanner)->plan(ChunkAssemblyFixtures::run(), 1, new RowRange(0, 4), ChunkAssemblyFixtures::anchors(4));
    $chunks = [ChunkAssemblyFixtures::chunk($plan->windows[0], new RowRange(0, 2)),
        ChunkAssemblyFixtures::chunk($plan->windows[0], new RowRange(2, 4))];
    $result = (new ChunkStitcher)->stitch($plan, $chunks);
    expect($result->rows)->toHaveCount(4)
        ->and($result->rowChunks['p0:r0'])->toEqual([$chunks[0]->id])
        ->and($result->rowChunks['p0:r3'])->toEqual([$chunks[1]->id]);
});
