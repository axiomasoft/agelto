<?php

declare(strict_types=1);

use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\DetailDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

beforeEach(fn () => LegacyBusinessNetwork::install());

test('диспетчер подаёт не больше лимита за тик', function (): void {
    Queue::fake();
    Procurement::factory()->count(3)->create(['detail_fetched_at' => null]);

    $count = app(DetailDispatcher::class)->dispatchDue(limit: 2);

    expect($count)->toBe(2);
    Queue::assertPushed(FetchProcurementDetailJob::class, 2);
});

test('повторный тик не создаёт второй detail-job для той же закупки', function (): void {
    Queue::fake();
    Procurement::factory()->create(['detail_fetched_at' => null]);

    $dispatcher = app(DetailDispatcher::class);
    $dispatcher->dispatchDue(limit: 1);
    $dispatcher->dispatchDue(limit: 1);

    Queue::assertPushed(FetchProcurementDetailJob::class, 1);
});
