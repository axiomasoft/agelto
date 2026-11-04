<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\RefreshFixtures;

beforeEach(function (): void {
    DetailFixtures::bind();
    Queue::fake();
});

// admission/outbox delivers work; this producer allocates durable periodic intents.
test('Process detail producer выделяет не больше лимита за тик без прямого dispatch', function (): void {
    $registry = Registry::factory()->eis()->create();
    foreach (range(1, 3) as $n) {
        RefreshFixtures::dueDetail('bounded-'.$n, $registry);
    }
    $before = DB::table('prc_process_work')->count();

    $allocated = app(RefreshRepository::class)->due(2);

    expect($allocated)->toHaveCount(2)
        ->and(DB::table('prc_process_work')->count())->toBe($before + 2)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->where('stage', 'detail')->count())->toBe(2)
        ->and(DB::table('prc_operation_intents')->where('stage', 'detail')->where('state', 'active')->count())->toBe(2);
    Queue::assertNothingPushed();
});

test('повторный tick сохраняет один active detail intent и work без второго dispatch', function (): void {
    $f = RefreshFixtures::dueDetail('dedup');
    $producer = app(RefreshRepository::class);
    $first = $producer->due(1);

    expect($first)->toHaveCount(1)->and($producer->due(1))->toBe([])
        ->and($producer->scheduleDetail($f['p']->id))->toBeNull()
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(2)
        ->and(DB::table('prc_operation_intents')->where('stage', 'detail')->where('state', 'active')->count())->toBe(1)
        ->and($f['p']->refresh()->detail_cycle_seq)->toBe(1);
    Queue::assertNothingPushed();
});
