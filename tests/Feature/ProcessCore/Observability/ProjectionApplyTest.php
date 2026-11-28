<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Projection\ProjectedEvent;
use App\Enums\Procurement\Process\Projection\ProjectionApplyStatus;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;
use App\Services\Procurement\Process\Projection\GovernanceMetricReducer;
use App\Services\Procurement\Process\Projection\MetricCatalog;
use App\Services\Procurement\Process\Projection\MetricProjector;
use App\Services\Procurement\Process\Projection\ProjectionRegistry;
use App\Services\Procurement\Process\Projection\RunMetricReducer;
use App\Services\Procurement\Process\Projection\RunProjector;
use App\Services\Procurement\Process\Projection\WorkMetricReducer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('platform-metrics-projection', 'run-state-reduction');

test('дубликат события: один receipt и ровно +1 на каждую серию; повторная доставка — Duplicate', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'deferred', 'item_retry', 'running');

    $first = app(MetricProjector::class)->apply($event);
    $second = app(MetricProjector::class)->apply($event);

    expect($first)->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and($second)->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate])
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $event->value)->count())->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'deferred')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'stage_deferred_total', MetricCatalog::dimensionKey('stage_deferred_total', ['platform' => 'manual', 'stage' => 'listing', 'reason' => 'item_retry'])))->toBe(1);
});

test('обратный порядок и поздний событие с меньшим seq: counters те же, run gauge читается из authoritative state', function (): void {
    ['work' => $work, 'run' => $run] = Fixture::workInRun('platform:eis');
    $running = Fixture::transition($work, 'running', null, 'admitted');
    $deferred = Fixture::transition($work, 'deferred', 'rate_limit', 'running');
    $succeeded = Fixture::transition($work, 'succeeded', null, 'running');
    DB::table('prc_process_run_work')->insert(['run_id' => $run->value, 'work_id' => $work->value->value, 'relation' => 'created', 'accounting_role' => 'required']);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['state' => 'succeeded']);

    // seq4 первым, затем seq3 и seq2: старое событие не возвращает статус назад и не теряется.
    foreach ([$succeeded, $deferred, $running] as $event) {
        app(MetricProjector::class)->apply($event);
    }
    $summary = app(RunProjector::class)->summary($run);

    expect(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running', 'eis')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'deferred', 'eis')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded', 'eis')))->toBe(1)
        ->and($summary?->required->succeeded)->toBe(1)
        ->and($summary?->required->deferred)->toBe(0);
});

test('receipt и counter атомарны: сбой UPSERT откатывает receipt, повтор после исправления ровно +1', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION prc_test_fail_totals() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'injected counter failure'; END $$;
        SQL);
    DB::unprepared('CREATE TRIGGER prc_test_fail_totals BEFORE INSERT OR UPDATE ON prc_process_metric_totals FOR EACH ROW EXECUTE FUNCTION prc_test_fail_totals()');

    expect(fn () => app(MetricProjector::class)->apply($event))->toThrow(QueryException::class)
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $event->value)->count())->toBe(0)
        ->and(Fixture::totalRows('work_transitions'))->toBe(0);

    DB::unprepared('DROP TRIGGER prc_test_fail_totals ON prc_process_metric_totals');
    $retry = app(MetricProjector::class)->apply($event);

    expect($retry)->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('maintenance откладывает apply без receipt и counters; после снятия gate событие применяется', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->ensureStates();
    DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->update([
        'mode' => 'maintenance', 'maintenance_token' => LedgerFixtures::uuid()->value, 'maintenance_started_at' => DB::raw('clock_timestamp()'),
    ]);

    $deferred = app(MetricProjector::class)->apply($event);

    expect($deferred)->toBe(['work_transitions' => ProjectionApplyStatus::Maintenance])
        ->and(Fixture::receipts('work_transitions'))->toBe(0)
        ->and(Fixture::totalRows('work_transitions'))->toBe(0);

    DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->update([
        'mode' => 'active', 'maintenance_token' => null, 'maintenance_started_at' => null,
    ]);
    expect(app(MetricProjector::class)->apply($event))->toBe(['work_transitions' => ProjectionApplyStatus::Applied]);
});

test('событие вне каталога изолируется: unknown state, unbounded cohort и чужая schema не блокируют остальные event IDs', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $bogusState = Fixture::rawWorkEvent($work, 'bogus_state');
    $futureSchema = Fixture::rawWorkEvent($work, 'succeeded', schemaVersion: 2);
    $good = Fixture::transition($work, 'succeeded', null, 'running');
    ['work' => $badCohort] = Fixture::workInRun('platform:Bad Cohort https://x');
    $badCohortEvent = Fixture::transition($badCohort, 'succeeded', null, 'running');

    $results = [
        'bogus' => app(MetricProjector::class)->apply($bogusState),
        'future' => app(MetricProjector::class)->apply($futureSchema),
        'cohort' => app(MetricProjector::class)->apply($badCohortEvent),
        'good' => app(MetricProjector::class)->apply($good),
    ];

    expect($results['bogus'])->toBe(['work_transitions' => ProjectionApplyStatus::SchemaInvalid])
        ->and($results['future'])->toBe(['work_transitions' => ProjectionApplyStatus::SchemaUnsupported])
        ->and($results['cohort'])->toBe(['work_transitions' => ProjectionApplyStatus::SchemaInvalid])
        ->and($results['good'])->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(DB::table('prc_process_projection_receipts')->whereIn('event_id', [$bogusState->value, $futureSchema->value, $badCohortEvent->value])->count())->toBe(0);
});

test('событие, не гидратируемое в типизированный факт (пустое new_state), — SchemaInvalid, а не исключение: batch не блокируется', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $malformed = Fixture::rawWorkEvent($work, '');
    $good = Fixture::transition($work, 'succeeded', null, 'running');

    expect(app(MetricProjector::class)->apply($malformed))->toBe(['work_transitions' => ProjectionApplyStatus::SchemaInvalid])
        ->and(app(MetricProjector::class)->apply($good))->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $malformed->value)->count())->toBe(0)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('source удалён: SourceUnavailable для каждого получателя, нулевая дельта, факт не воссоздаётся из payload', function (): void {
    $missing = LedgerFixtures::uuid();

    $results = app(MetricProjector::class)->apply($missing);

    expect(array_map(static fn (ProjectionApplyStatus $status): string => $status->value, $results))->toBe([
        'work_transitions' => 'source_unavailable', 'run_transitions' => 'source_unavailable', 'governance_transitions' => 'source_unavailable',
    ])->and(DB::table('prc_process_projection_receipts')->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->count())->toBe(0);
});

test('расхождение catalog_hash запрещает live apply: generation и totals не меняются', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $event = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->ensureStates();
    DB::statement("UPDATE prc_process_projection_states SET catalog_hash = decode(repeat('ab', 32), 'hex') WHERE projector_key = 'work_transitions'");

    expect(app(MetricProjector::class)->apply($event))->toBe(['work_transitions' => ProjectionApplyStatus::CatalogMismatch])
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(Fixture::receipts('work_transitions'))->toBe(0);
});

test('counts считаются по времени перехода: последующий переход не переписывает ранее принятые', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $done = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->apply($done);
    $later = Fixture::transition($work, 'superseded', 'input_invalidated', 'succeeded');
    app(MetricProjector::class)->apply($later);

    expect(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'superseded')))->toBe(1);
});

test('run, control и incident transitions идут своим получателям; applyAll даёт по одному receipt на событие и получателя', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::transition($work, 'running', null, 'admitted');
    Fixture::applyAll();

    $events = DB::table('prc_process_transitions')->count();

    expect(DB::table('prc_process_projection_receipts')->count())->toBe($events)
        ->and(Fixture::total('run_transitions', 'run_transition_total', MetricCatalog::dimensionKey('run_transition_total', ['state' => 'collecting'])))->toBe(1)
        ->and(Fixture::receipts('governance_transitions'))->toBe(0);
});

test('каталог закрыт: unbounded label, лишнее измерение и чужое значение отвергаются, число серий ограничено', function (): void {
    $invalid = [
        ['work_transition_total', ['platform' => 'eis', 'stage' => 'download', 'state' => 'https://x.example/?t=1']],
        ['work_transition_total', ['platform' => 'EIS', 'stage' => 'download', 'state' => 'ready']],
        ['work_transition_total', ['platform' => 'eis', 'stage' => 'download', 'state' => 'ready', 'work_id' => 'x']],
        ['stage_deferred_total', ['platform' => 'eis', 'stage' => 'download', 'reason' => 'free text']],
        ['unknown_total', []],
    ];
    foreach ($invalid as [$metric, $dimensions]) {
        expect(fn () => MetricCatalog::dimensionKey($metric, $dimensions))->toThrow(ProjectionSchemaError::class);
    }

    expect(MetricCatalog::dimensionKey('work_transition_total', ['platform' => 'eis', 'stage' => 'download', 'state' => 'ready']))
        ->toBe('v1;platform=eis;stage=download;state=ready')
        ->and(MetricCatalog::platformOf('platform:eis'))->toBe('eis')
        ->and(fn () => MetricCatalog::platformOf('platform:eis;drop'))->toThrow(ProjectionSchemaError::class);
});

test('registry boot-validated: дубль серии и пропущенный kind отвергаются, hash зависит от версии и серий', function (): void {
    $registry = new ProjectionRegistry;

    expect(array_keys($registry->all()))->toBe(['work_transitions', 'run_transitions', 'governance_transitions'])
        ->and(fn () => new ProjectionRegistry([new WorkMetricReducer, new WorkMetricReducer, new RunMetricReducer, new GovernanceMetricReducer]))
        ->toThrow(InvalidArgumentException::class, 'projector key')
        ->and(fn () => new ProjectionRegistry([new WorkMetricReducer, new RunMetricReducer]))
        ->toThrow(InvalidArgumentException::class, 'every kind needs a reducer')
        ->and($registry->catalogHash(new WorkMetricReducer)->hex)->not->toBe($registry->catalogHash(new RunMetricReducer)->hex);

    // Один reducer не может владеть серией другого: ownership проверяется при построении registry.
    $greedy = new class implements ProjectionReducer
    {
        public function key(): string
        {
            return 'greedy';
        }

        public function version(): int
        {
            return 1;
        }

        public function entityKinds(): array
        {
            return ['work'];
        }

        public function metrics(): array
        {
            return ['work_transition_total'];
        }

        public function reduce(ProjectedEvent $event): array
        {
            return [];
        }
    };
    expect(fn () => new ProjectionRegistry([new WorkMetricReducer, $greedy, new RunMetricReducer, new GovernanceMetricReducer]))
        ->toThrow(InvalidArgumentException::class, 'metric ownership');
});

test('seed state: generation 1, active, catalog_hash из registry; повтор не затирает существующий state', function (): void {
    app(MetricProjector::class)->ensureStates();
    DB::table('prc_process_projection_states')->where('projector_key', 'run_transitions')->update(['active_generation' => 7]);
    app(MetricProjector::class)->ensureStates();
    $states = DB::table('prc_process_projection_states')->orderBy('projector_key')->get()->keyBy('projector_key');

    expect($states->keys()->all())->toBe(['governance_transitions', 'run_transitions', 'work_transitions'])
        ->and((int) $states['run_transitions']->active_generation)->toBe(7)
        ->and((int) $states['work_transitions']->active_generation)->toBe(1)
        ->and($states['work_transitions']->mode)->toBe('active');
});
