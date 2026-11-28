<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Projection\PruneResult;
use App\Enums\Procurement\Process\Projection\ProjectionApplyStatus;
use App\Services\Procurement\Process\Projection\MetricProjector;
use App\Services\Procurement\Process\Projection\ProjectionRetention;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('projection-retention', 'platform-metrics-projection');

function publishFanout(ProcessUuid $event): void
{
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$event->value)->update(['published_at' => DB::raw('clock_timestamp()')]);
}

test('prune удаляет событие только с receipt и подтверждённым fanout; затем история необратимо неполна', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    $retention = app(ProjectionRetention::class);
    app(MetricProjector::class)->ensureStates();

    // Нет receipt: required consumer ещё не применил событие — source обязан остаться.
    expect($retention->prune([$event]))->toEqual(new PruneResult(0, 1, false));

    app(MetricProjector::class)->apply($event);
    // Receipt есть, но fanout ещё не подтверждён relay/projector: событие остаётся.
    expect($retention->prune([$event])->pruned)->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('event_id', $event->value)->exists())->toBeTrue()
        ->and((bool) DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('history_complete'))->toBeTrue();

    publishFanout($event);
    $pruned = $retention->prune([$event]);

    expect($pruned->pruned)->toBe(1)
        ->and($pruned->ineligible)->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('event_id', $event->value)->exists())->toBeFalse()
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $event->value)->exists())->toBeFalse()
        ->and((bool) DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('history_complete'))->toBeFalse()
        ->and(DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('history_pruned_at'))->not->toBeNull()
        // Затронут только projector этого kind: run/governance history остаётся полной.
        ->and((bool) DB::table('prc_process_projection_states')->where('projector_key', 'run_transitions')->value('history_complete'))->toBeTrue()
        // Live totals не уменьшились из-за retention.
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('старый queue payload после lawful prune даёт ноль приращений; повтор prune идемпотентен', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->apply($event);
    publishFanout($event);
    app(ProjectionRetention::class)->prune([$event]);
    $before = Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded'));

    $replay = app(MetricProjector::class)->apply($event);
    $again = app(ProjectionRetention::class)->prune([$event]);

    expect($replay)->toBe(['work_transitions' => ProjectionApplyStatus::SourceUnavailable, 'run_transitions' => ProjectionApplyStatus::SourceUnavailable, 'governance_transitions' => ProjectionApplyStatus::SourceUnavailable])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe($before)
        ->and($again->pruned)->toBe(0)
        ->and($again->ineligible)->toBe(0);
});

test('maintenance откладывает prune целиком: source остаётся, история полна', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->apply($event);
    publishFanout($event);
    DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->update([
        'mode' => 'maintenance', 'maintenance_token' => LedgerFixtures::uuid()->value, 'maintenance_started_at' => DB::raw('clock_timestamp()'),
    ]);

    $result = app(ProjectionRetention::class)->prune([$event]);

    expect($result->deferredForMaintenance)->toBeTrue()
        ->and($result->pruned)->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('event_id', $event->value)->exists())->toBeTrue()
        ->and((bool) DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('history_complete'))->toBeTrue();
});

test('FK RESTRICT защищает историю от неупорядоченного удаления; batch ограничен политикой', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->apply($event);

    $constraint = DB::selectOne("SELECT confdeltype FROM pg_constraint WHERE conrelid = 'prc_process_projection_receipts'::regclass
        AND confrelid = 'prc_process_transitions'::regclass AND contype = 'f'");
    expect($constraint->confdeltype)->toBe('r')
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_process_transitions')->where('event_id', $event->value)->delete()))->toBe('23001')
        ->and(DB::table('prc_process_transitions')->where('event_id', $event->value)->exists())->toBeTrue();

    config()->set('process.projection.prune_batch', 1);
    expect(fn () => app(ProjectionRetention::class)->prune([$event, LedgerFixtures::uuid()]))->toThrow(InvalidArgumentException::class);
});
