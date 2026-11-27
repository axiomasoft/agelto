<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Projection\PruneResult;
use App\Repositories\Procurement\Process\Work\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Diagnostics\ProcessDiagnosticsInspector;
use App\Services\Procurement\Process\Projection\MetricProjector;
use App\Services\Procurement\Process\Projection\ProjectionRetention;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures;

function retentionOutbox(ProcessUuid $event): Builder
{
    return DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$event->value);
}

test('очистка outbox удаляет старые подтверждённые сообщения по порядку и соблюдает лимит', function (): void {
    ['work' => $work] = ProjectionFixtures::workInRun();
    $oldest = ProjectionFixtures::transition($work, 'ready');
    $old = ProjectionFixtures::transition($work, 'ready');
    $unpublished = ProjectionFixtures::transition($work, 'ready');
    $recent = ProjectionFixtures::transition($work, 'ready');

    retentionOutbox($oldest)->update(['published_at' => DB::raw("clock_timestamp() - interval '45 days'")]);
    retentionOutbox($old)->update(['published_at' => DB::raw("clock_timestamp() - interval '40 days'")]);
    retentionOutbox($unpublished)->update([
        'available_at' => DB::raw("clock_timestamp() - interval '50 days'"),
        'last_error_code' => 'quarantined_unsupported_schema',
    ]);
    retentionOutbox($recent)->update([
        'available_at' => DB::raw("clock_timestamp() - interval '50 days'"),
        'published_at' => DB::raw('clock_timestamp()'),
    ]);

    expect(app(OutboxRepository::class)->prunePublished(retentionDays: 30, limit: 1))->toBe(1)
        ->and(retentionOutbox($oldest)->exists())->toBeFalse()
        ->and(retentionOutbox($old)->exists())->toBeTrue();

    $this->artisan('process:retention', ['--limit' => 1])
        ->expectsOutputToContain('Published outbox messages removed: 1.')
        ->assertSuccessful();

    expect(retentionOutbox($old)->exists())->toBeFalse()
        ->and(retentionOutbox($unpublished)->exists())->toBeTrue()
        ->and(retentionOutbox($recent)->exists())->toBeTrue()
        ->and(app(OutboxRepository::class)->prunePublished(retentionDays: 30, limit: 1))->toBe(0);
});

test('пакетная очистка использует четыре SQL-запроса и сохраняет события без receipt или без подтверждения fanout', function (): void {
    ['work' => $work] = ProjectionFixtures::workInRun();
    $eligible = [ProjectionFixtures::transition($work, 'ready'), ProjectionFixtures::transition($work, 'ready')];
    $withoutReceipt = ProjectionFixtures::transition($work, 'ready');
    $pending = ProjectionFixtures::transition($work, 'ready');
    $projector = app(MetricProjector::class);
    foreach ([...$eligible, $pending] as $event) {
        $projector->apply($event);
    }
    foreach ([...$eligible, $withoutReceipt] as $event) {
        retentionOutbox($event)->update(['published_at' => DB::raw('clock_timestamp()')]);
    }

    $retention = app(ProjectionRetention::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $result = $retention->prune([...$eligible, $withoutReceipt, $pending]);
        $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'prc_process_'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($result)->toEqual(new PruneResult(pruned: 2, ineligible: 2, deferredForMaintenance: false))
        ->and($queries)->toHaveCount(4)
        ->and($retention->prune($eligible))->toEqual(new PruneResult(pruned: 0, ineligible: 0, deferredForMaintenance: false));
    foreach ($eligible as $event) {
        $this->assertDatabaseMissing('prc_process_transitions', ['event_id' => $event->value]);
        $this->assertDatabaseMissing('prc_process_projection_receipts', ['event_id' => $event->value]);
    }
    foreach ([$withoutReceipt, $pending] as $event) {
        $this->assertDatabaseHas('prc_process_transitions', ['event_id' => $event->value]);
    }
    $this->assertDatabaseHas('prc_process_projection_receipts', ['event_id' => $pending->value]);
});

test('команда истории соблюдает предел пакетов и времени, а с настройками по умолчанию обрабатывает несколько пакетов', function (): void {
    config()->set('process.projection.prune_batch', 2);
    ['work' => $work] = ProjectionFixtures::workInRun();
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['state' => 'cancelled', 'reason_code' => 'cancelled']);
    $projector = app(MetricProjector::class);
    $pending = ProjectionFixtures::transition($work, 'ready');
    $projector->apply($pending);
    DB::table('prc_process_transitions')->where('event_id', $pending->value)
        ->update(['occurred_at' => DB::raw("clock_timestamp() - interval '500 days'")]);

    $eligible = [];
    for ($i = 0; $i < 4; $i++) {
        $event = ProjectionFixtures::transition($work, 'ready');
        $projector->apply($event);
        retentionOutbox($event)->update(['published_at' => DB::raw('clock_timestamp()')]);
        DB::table('prc_process_transitions')->where('event_id', $event->value)
            ->update(['occurred_at' => DB::raw("clock_timestamp() - interval '400 days'")]);
        $eligible[] = $event->value;
    }
    $tail = ProjectionFixtures::transition(work: $work, new: 'cancelled', reason: 'cancelled', previous: 'ready');
    $events = DB::table('prc_process_transitions')->whereIn('event_id', $eligible);

    $this->artisan('process:prune-history', ['--max-batches' => 0])->assertExitCode(2);
    expect($events->count())->toBe(4);

    $this->artisan('process:prune-history', ['--max-batches' => 1])
        ->expectsOutputToContain('Удалено 1, оставлено до подтверждения projections 1.')
        ->assertSuccessful();
    expect($events->count())->toBe(3);

    $clock = app(ProcessClock::class);
    $this->mock(ProcessClock::class, function (MockInterface $mock) use ($clock): void {
        $mock->shouldReceive('dbNow')->once()->andReturn($clock->dbNow());
        $mock->shouldReceive('monotonicNs')->andReturn(0, 1, 30_000_000_000);
    });
    $this->artisan('process:prune-history')
        ->expectsOutputToContain('Удалено 1, оставлено до подтверждения projections 1.')
        ->assertSuccessful();
    $this->instance(ProcessClock::class, $clock);
    expect($events->count())->toBe(2);

    $this->artisan('process:prune-history')
        ->expectsOutputToContain('Удалено 2, оставлено до подтверждения projections 1.')
        ->assertSuccessful();
    expect($events->count())->toBe(0);
    $this->assertDatabaseHas('prc_process_transitions', ['event_id' => $pending->value]);
    $this->assertDatabaseHas('prc_process_transitions', ['event_id' => $tail->value]);
});

test('диагностика определяет возраст outbox по часам базы при сдвиге часов приложения', function (): void {
    ['work' => $work] = ProjectionFixtures::workInRun();
    $stale = ProjectionFixtures::transition($work, 'ready');
    retentionOutbox($stale)->update(['available_at' => DB::raw("clock_timestamp() - interval '20 minutes'")]);
    $this->travel(1)->year();

    expect(app(ProcessDiagnosticsInspector::class)->staleOutbox())
        ->toBe(['stale_outbox' => 'Неопубликованных сообщений в outbox (>15мин): 1']);
});
