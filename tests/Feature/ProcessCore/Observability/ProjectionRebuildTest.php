<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Enums\Procurement\Process\ProjectionApplyStatus;
use App\Enums\Procurement\Process\RebuildStatus;
use App\Repositories\Procurement\Process\ProjectionRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\ProjectionPolicy;
use App\Services\Procurement\Process\ProjectionRebuilder;
use App\Services\Procurement\Process\ProjectionRegistry;
use App\Services\Procurement\Process\ProjectionRetention;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('platform-metrics-projection', 'projection-retention');

/** Три work transitions + run/work создания: часть применена live, остальные ждут (unapplied). */
function rebuildScenario(): array
{
    ['work' => $work] = Fixture::workInRun();
    $running = Fixture::transition($work, 'running', null, 'admitted');
    $deferred = Fixture::transition($work, 'deferred', 'rate_limit', 'running');
    $succeeded = Fixture::transition($work, 'succeeded', null, 'running');
    app(MetricProjector::class)->apply($running);

    return compact('work', 'running', 'deferred', 'succeeded');
}

function rebuildState(string $key = 'work_transitions'): object
{
    return DB::table('prc_process_projection_states')->where('projector_key', $key)->first();
}

test('rebuild пересобирает totals из ledger: новая generation, receipts выданы, дубликат после flip не удваивает', function (): void {
    $scenario = rebuildScenario();
    $outboxBefore = DB::table('prc_process_outbox')->count();
    $transitionsBefore = DB::table('prc_process_transitions')->count();

    $result = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    // work-события: создание (ready) + running + deferred + succeeded = 4; live применил только running.
    expect($result->status)->toBe(RebuildStatus::Rebuilt)
        ->and($result->newGeneration)->toBe(2)
        ->and($result->processedEvents)->toBe(4)
        ->and($result->previouslyUnapplied)->toBe(3)
        ->and($result->digest)->toMatch('/^[0-9a-f]{64}$/')
        ->and(count($result->oldTotals))->toBe(1)
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_token)->toBeNull()
        ->and(rebuildState()->rebuilt_at)->not->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe(4)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        // totals старой generation очищены отдельной bounded tx, активные остались.
        ->and(DB::table('prc_process_metric_totals')->where('projector_key', 'work_transitions')->where('generation', 1)->count())->toBe(0)
        // Rebuild не запускает work, не создаёт events и outbox wakeups.
        ->and(DB::table('prc_process_outbox')->count())->toBe($outboxBefore)
        ->and(DB::table('prc_process_transitions')->count())->toBe($transitionsBefore);

    // Событие из snapshot с ещё pending outbox получает no-op; новое событие после flip применяется к generation 2.
    expect(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
    $late = Fixture::transition($scenario['work'], 'superseded', 'input_invalidated', 'succeeded');
    expect(app(MetricProjector::class)->apply($late))->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'superseded')))->toBe(1)
        ->and(Fixture::generation('work_transitions'))->toBe(2);
});

test('rebuild идемпотентен по итогу: повторный rebuild даёт те же totals на следующей generation', function (): void {
    rebuildScenario();
    $first = app(ProjectionRebuilder::class)->rebuild('work_transitions');
    $second = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    expect($second->status)->toBe(RebuildStatus::Rebuilt)
        ->and($second->newGeneration)->toBe(3)
        ->and($second->previouslyUnapplied)->toBe(0)
        ->and($second->digest)->toBe($first->digest)
        ->and($second->newTotals)->toEqual($first->newTotals);
});

test('история неполна после retention: rebuild отказывает, active generation и receipts не меняются', function (): void {
    $scenario = rebuildScenario();
    app(MetricProjector::class)->apply($scenario['succeeded']);
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$scenario['succeeded']->value)->update(['published_at' => DB::raw('clock_timestamp()')]);
    $receiptsBefore = Fixture::receipts('work_transitions');
    expect(app(ProjectionRetention::class)->prune([$scenario['succeeded']])->pruned)->toBe(1);

    $result = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::HistoryUnavailable)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->history_complete)->toBeFalse()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore - 1)
        // Live totals не уменьшились из-за retention.
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('превышение max_events отказывает TooLarge: прежняя generation, receipts и gate нетронуты', function (): void {
    rebuildScenario();
    config()->set('process.projection.rebuild_page', 1);
    config()->set('process.projection.rebuild_max_events', 2);
    $receiptsBefore = Fixture::receipts('work_transitions');

    $result = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::TooLarge)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0);
});

test('deadline: просроченный rebuild откатывается целиком Timeout, gate снят', function (): void {
    rebuildScenario();
    $clock = new class implements ProcessClock
    {
        private int $now = 0;

        public function dbNow(): DbInstant
        {
            throw new LogicException('not used');
        }

        public function monotonicNs(): int
        {
            return $this->now += 60_000_000_000;
        }

        public function sleep(Duration $duration): void {}
    };
    $rebuilder = new ProjectionRebuilder(app(ProjectionRegistry::class), app(ProjectionRepository::class), app(ProjectionPolicy::class), app(ProcessTransaction::class), $clock);
    $receiptsBefore = Fixture::receipts('work_transitions');

    $result = $rebuilder->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::Timeout)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore);
});

test('deadline действует и на записи totals: истёк посреди записи серий — Timeout, flip не происходит, receipts и generation прежние', function (): void {
    rebuildScenario();
    // Часы «истекают», как только rebuild записал первую серию новой generation: старый код проверял
    // deadline только на страницах событий и публиковал generation после просроченной записи totals.
    $clock = new class implements ProcessClock
    {
        public function dbNow(): DbInstant
        {
            throw new LogicException('not used');
        }

        public function monotonicNs(): int
        {
            return DB::table('prc_process_metric_totals')->where('generation', 2)->exists() ? 3_600_000_000_000 : 0;
        }

        public function sleep(Duration $duration): void {}
    };
    $rebuilder = new ProjectionRebuilder(app(ProjectionRegistry::class), app(ProjectionRepository::class), app(ProjectionPolicy::class), app(ProcessTransaction::class), $clock);
    $receiptsBefore = Fixture::receipts('work_transitions');
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();

    $result = $rebuilder->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::Timeout)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);
});

test('deadline после publish: истёк между последней проверкой и commit — Timeout, новая generation, receipts и totals откатываются', function (): void {
    rebuildScenario();
    // Часы «истекают» ровно после публикации generation (в той же tx): последняя проверка перед publish ещё проходит,
    // как при паузе процесса между проверкой и flip. Без проверки ПОСЛЕ publish rebuild вернул бы Rebuilt.
    $clock = new class implements ProcessClock
    {
        public function dbNow(): DbInstant
        {
            throw new LogicException('not used');
        }

        public function monotonicNs(): int
        {
            return (int) DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('active_generation') >= 2 ? 3_600_000_000_000 : 0;
        }

        public function sleep(Duration $duration): void {}
    };
    $rebuilder = new ProjectionRebuilder(app(ProjectionRegistry::class), app(ProjectionRepository::class), app(ProjectionPolicy::class), app(ProcessTransaction::class), $clock);
    $receiptsBefore = Fixture::receipts('work_transitions');
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();

    $result = $rebuilder->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::Timeout)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_token)->toBeNull()
        ->and(rebuildState()->rebuilt_at)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);
});

test('deadline с реальным временем: пауза процесса дольше deadline прямо перед publish — Timeout, flip не committed', function (): void {
    rebuildScenario();
    config()->set('process.projection.rebuild_deadline_seconds', 1);
    $rebuilder = new ProjectionRebuilder(app(ProjectionRegistry::class), app(ProjectionRepository::class), new ProjectionPolicy(config()), app(ProcessTransaction::class), app(ProcessClock::class));
    $receiptsBefore = Fixture::receipts('work_transitions');
    $paused = false;
    $armed = true;
    // Пауза после последней проверки и SET LOCAL statement_timeout, непосредственно перед UPDATE flip.
    DB::beforeExecuting(function (string $query) use (&$paused, &$armed): void {
        if ($armed && str_contains($query, 'SET active_generation')) {
            $armed = false;
            $paused = true;
            usleep(1_300_000);
        }
    });

    try {
        $result = $rebuilder->rebuild('work_transitions');
    } finally {
        $armed = false;
    }

    expect($paused)->toBeTrue()
        ->and($result->status)->toBe(RebuildStatus::Timeout)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0);
});

test('факт ledger, не выражаемый типизированным событием (пустое состояние), отменяет rebuild UnsupportedFact', function (): void {
    $scenario = rebuildScenario();
    Fixture::rawWorkEvent($scenario['work'], '');
    $receiptsBefore = Fixture::receipts('work_transitions');

    $result = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::UnsupportedFact)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore);
});

test('неизвестный факт в ledger отменяет весь rebuild UnsupportedFact без частичного flip', function (): void {
    $scenario = rebuildScenario();
    Fixture::rawWorkEvent($scenario['work'], 'bogus_state');
    $receiptsBefore = Fixture::receipts('work_transitions');

    $result = app(ProjectionRebuilder::class)->rebuild('work_transitions');

    expect($result->status)->toBe(RebuildStatus::UnsupportedFact)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(rebuildState()->mode)->toBe('active')
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0);
});

test('расхождение catalog_hash запрещает rebuild; неизвестный projector — ошибка', function (): void {
    app(MetricProjector::class)->ensureStates();
    DB::statement("UPDATE prc_process_projection_states SET catalog_hash = decode(repeat('cd', 32), 'hex') WHERE projector_key = 'work_transitions'");

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::CatalogMismatch)
        ->and(fn () => app(ProjectionRebuilder::class)->rebuild('nope'))->toThrow(InvalidArgumentException::class);
});

test('занятый gate: второй rebuild получает Busy; resume снимает gate только владельцу token и не трогает totals', function (): void {
    $scenario = rebuildScenario();
    $repository = app(ProjectionRepository::class);
    $token = LedgerFixtures::uuid();
    $repository->seed('work_transitions', app(ProjectionRegistry::class)->catalogHash(app(ProjectionRegistry::class)->get('work_transitions')));
    DB::transaction(function () use ($repository, $token): void {
        $repository->stateForUpdate('work_transitions');
        $repository->enterMaintenance('work_transitions', $token);
    });
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Busy)
        // Чужой gate остаётся: busy rebuild его не снимал.
        ->and(rebuildState()->mode)->toBe('maintenance')
        ->and(app(MetricProjector::class)->apply($scenario['deferred']))->toBe(['work_transitions' => ProjectionApplyStatus::Maintenance])
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', LedgerFixtures::uuid()))->toBeFalse()
        ->and(rebuildState()->mode)->toBe('maintenance')
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', $token))->toBeTrue()
        ->and(rebuildState()->mode)->toBe('active')
        ->and(rebuildState()->maintenance_started_at)->toBeNull()
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore)
        // Повторный resume идемпотентен: gate уже снят, второй вызов ничего не меняет.
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', $token))->toBeFalse();
});

test('artisan: rebuild завершается кодом 0, неизвестный projector — INVALID, resume без token — INVALID', function (): void {
    rebuildScenario();

    $this->artisan('process:metrics-rebuild', ['projector' => 'work_transitions'])->assertExitCode(0);
    $this->artisan('process:metrics-rebuild', ['projector' => 'nope'])->assertExitCode(2);
    $this->artisan('process:metrics-rebuild-resume', ['projector' => 'work_transitions'])->assertExitCode(2);
    $this->artisan('process:metrics-rebuild-resume', ['projector' => 'work_transitions', '--expected-token' => LedgerFixtures::uuid()->value])->assertExitCode(1);
    expect(Fixture::generation('work_transitions'))->toBe(2);
});
