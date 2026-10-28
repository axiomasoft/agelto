<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PruneResult;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ProjectionApplyStatus;
use App\Enums\Procurement\Process\RebuildStatus;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProjectionRebuilder;
use App\Services\Procurement\Process\ProjectionRetention;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t46', 't20', 't18', 'p115');

/*
 * observability#s08, fault assertions 1–6 на РЕАЛЬНЫХ PostgreSQL connections. Feature-тесты работают
 * внутри transaction-обёртки RefreshDatabase, поэтому здесь она закрывается: данные committed, workers
 * (отдельные PHP-процессы) видят их, а rebuild в этом процессе и в workers открывает outermost tx и
 * выставляет production-уровень REPEATABLE READ. Барьеры — advisory lock + ожидание реального lock-waiter
 * в pg_stat_activity (FinalizeFixtures::holdUntilWaiter/afterHolder), а не sleep.
 */
beforeEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    projConcClean();
});

afterEach(function (): void {
    projConcClean();
    // Возвращаем (пустую) обёртку: teardown RefreshDatabase откатит её, а не пересоздаст schema.
    DB::beginTransaction();
});

function projConcClean(): void
{
    projConcDropTrigger();
    ExecutionFixtures::truncateLedger();
    DB::statement('TRUNCATE prc_process_metric_totals, prc_process_projection_receipts, prc_process_projection_states');
}

function projConcDropTrigger(): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS prc_test_prune_trigger ON prc_process_projection_states');
    DB::unprepared('DROP FUNCTION IF EXISTS prc_test_prune_trigger()');
    DB::unprepared('DROP TRIGGER IF EXISTS prc_test_projection_trigger ON prc_process_metric_totals');
    DB::unprepared('DROP FUNCTION IF EXISTS prc_test_projection_trigger()');
}

/** Trigger на записи totals: даёт детерминированное окно внутри rebuild или инъекцию сбоя после первой серии. */
function projConcInstallTrigger(string $body): void
{
    DB::unprepared('CREATE OR REPLACE FUNCTION prc_test_projection_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$body.' RETURN NEW; END $$');
    DB::unprepared('CREATE TRIGGER prc_test_projection_trigger BEFORE INSERT ON prc_process_metric_totals FOR EACH ROW EXECUTE FUNCTION prc_test_projection_trigger()');
}

/** Окно 0.3 s на каждую серию; одновременно доказывает, что rebuild идёт в REPEATABLE READ. */
function projConcSlowRepeatableReadTrigger(): void
{
    projConcInstallTrigger("IF current_setting('transaction_isolation') <> 'repeatable read' THEN RAISE EXCEPTION 'rebuild must run REPEATABLE READ'; END IF; PERFORM pg_sleep(0.3);");
}

/** @return array{work: WorkId,running: ProcessUuid, succeeded: ProcessUuid} */
function projConcScenario(): array
{
    app(MetricProjector::class)->ensureStates();

    return DB::transaction(function (): array {
        ['work' => $work] = Fixture::workInRun();

        return [
            'work' => $work,
            'running' => Fixture::transition($work, 'running', null, 'admitted'),
            'succeeded' => Fixture::transition($work, 'succeeded', null, 'running'),
        ];
    });
}

function projConcApplyCode(ProcessUuid $event): string
{
    return "return array_map(static fn (\$status) => \$status->value, app(\\App\\Services\\Procurement\\Process\\MetricProjector::class)->apply(new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$event->value}')));";
}

function projConcRebuildCode(): string
{
    return "return ['status' => app(\\App\\Services\\Procurement\\Process\\ProjectionRebuilder::class)->rebuild('work_transitions')->status->value];";
}

/** Шаг A rebuild: state FOR UPDATE → maintenance + token (внутри tx вызывающего barrier). */
function projConcGateCode(string $token): string
{
    return "\$repository = app(\\App\\Repositories\\Procurement\\Process\\ProjectionRepository::class); \$repository->stateForUpdate('work_transitions'); return ['entered' => \$repository->enterMaintenance('work_transitions', new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$token}'))];";
}

function projConcPruneCode(ProcessUuid $event): string
{
    return "\$result = app(\\App\\Services\\Procurement\\Process\\ProjectionRetention::class)->prune([new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$event->value}')]); return ['pruned' => \$result->pruned, 'deferred' => \$result->deferredForMaintenance];";
}

/** Holder: tx открыта, locks операции удерживаются, пока не появится реальный lock-waiter. */
function projConcHold(string $operation): string
{
    return '$fx = \Tests\Support\ProcessCore\FinalizeFixtures::class; return $fx::holdUntilWaiter(function () { '.$operation.' });';
}

/** Waiter: стартует только после того, как holder взял свои locks. */
function projConcAfter(string $operation): string
{
    return '$fx = \Tests\Support\ProcessCore\FinalizeFixtures::class; return $fx::afterHolder(function () { '.$operation.' });';
}

/** Holder state-lock, который ждёт ДВУХ lock-waiters (два конкурирующих rebuild), и только потом коммитит. */
function projConcHoldForTwoWaiters(): string
{
    return <<<'PHP'
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            app(\App\Repositories\Procurement\Process\ProjectionRepository::class)->stateForUpdate('work_transitions');
            \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(?)', [\Tests\Support\ProcessCore\FinalizeFixtures::SIGNAL]);
            $deadline = hrtime(true) + 10_000_000_000;
            while ((int) \Illuminate\Support\Facades\DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'")->n < 2) {
                if (hrtime(true) > $deadline) {
                    throw new \RuntimeException('Process barrier timed out.');
                }
                usleep(5_000);
            }
            \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\DB::rollBack();

            throw $exception;
        }
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(?)', [\Tests\Support\ProcessCore\FinalizeFixtures::SIGNAL]);

        return ['held' => true];
        PHP;
}

/** Producer ждёт, пока rebuild реально спит внутри snapshot, и коммитит новое событие. */
function projConcProducerDuringSnapshot(string $workId): string
{
    return str_replace('__WORK__', $workId, <<<'PHP'
        $deadline = hrtime(true) + 10_000_000_000;
        while ((int) \Illuminate\Support\Facades\DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event = 'PgSleep'")->n < 1) {
            if (hrtime(true) > $deadline) {
                throw new \RuntimeException('Process barrier timed out.');
            }
            usleep(5_000);
        }
        $event = \Illuminate\Support\Facades\DB::transaction(fn () => \Tests\Feature\ProcessCore\Observability\ProjectionFixtures::transition(
            new \App\Dto\Procurement\Process\WorkId(new \App\Dto\Procurement\Process\ProcessUuid('__WORK__')), 'superseded', 'input_invalidated', 'succeeded',
        ));

        return ['event' => $event->value];
        PHP);
}

function projConcState(): object
{
    return DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->first();
}

function projConcPublishFanout(ProcessUuid $event): void
{
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$event->value)->update(['published_at' => DB::raw('clock_timestamp()')]);
}

test('два одновременных duplicate apply: один receipt, ровно +1, второй получает Duplicate', function (): void {
    $scenario = projConcScenario();

    $results = ConcurrentTransactions::concurrently([
        'first' => projConcApplyCode($scenario['succeeded']),
        'second' => projConcApplyCode($scenario['succeeded']),
    ]);
    $statuses = [$results['first']['work_transitions'], $results['second']['work_transitions']];
    sort($statuses);

    expect($statuses)->toBe(['applied', 'duplicate'])
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $scenario['succeeded']->value)->count())->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('apply держит state FOR SHARE, rebuild ждёт его (FOR UPDATE): событие не потеряно и не удвоено в новой generation', function (): void {
    $scenario = projConcScenario();

    $results = ConcurrentTransactions::concurrently([
        'apply' => projConcHold(projConcApplyCode($scenario['succeeded'])),
        'rebuild' => projConcAfter(projConcRebuildCode()),
    ]);

    expect($results['apply'])->toBe(['work_transitions' => 'applied'])
        ->and($results['rebuild'])->toBe(['status' => 'rebuilt'])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(projConcState()->mode)->toBe('active')
        // Три work-события (создание, running, succeeded): receipt каждого ровно один, apply не дублирован rebuild-ом.
        ->and(Fixture::receipts('work_transitions'))->toBe(3)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running')))->toBe(1);
});

test('после commit maintenance gate новый apply не обновляет прежнюю generation: Maintenance, ни receipt, ни counter; resume снимает gate', function (): void {
    $scenario = projConcScenario();
    $token = LedgerFixtures::uuid();

    $results = ConcurrentTransactions::concurrently([
        'gate' => projConcHold(projConcGateCode($token->value)),
        'apply' => projConcAfter(projConcApplyCode($scenario['succeeded'])),
    ]);

    expect($results['gate'])->toBe(['entered' => true])
        ->and($results['apply'])->toBe(['work_transitions' => 'maintenance'])
        ->and(Fixture::receipts('work_transitions'))->toBe(0)
        ->and(Fixture::totalRows('work_transitions'))->toBe(0)
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(projConcState()->mode)->toBe('maintenance')
        ->and(projConcState()->maintenance_token)->toBe($token->value);

    expect(app(ProjectionRebuilder::class)->resume('work_transitions', $token))->toBeTrue()
        ->and(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Applied]);
});

test('два конкурирующих rebuild: ровно один token и один flip, второй получает Busy; оба в REPEATABLE READ', function (): void {
    projConcScenario();
    projConcSlowRepeatableReadTrigger();

    $results = ConcurrentTransactions::concurrently([
        'holder' => projConcHoldForTwoWaiters(),
        'first' => projConcAfter(projConcRebuildCode()),
        'second' => projConcAfter(projConcRebuildCode()),
    ]);
    $statuses = [$results['first']['status'], $results['second']['status']];
    sort($statuses);

    expect($statuses)->toBe(['busy', 'rebuilt'])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(projConcState()->mode)->toBe('active')
        ->and(projConcState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe(3);
});

test('событие, committed во время snapshot rebuild, не теряется: нет receipt в snapshot, после flip применяется к новой generation', function (): void {
    $scenario = projConcScenario();
    projConcSlowRepeatableReadTrigger();

    try {
        $results = ConcurrentTransactions::concurrently([
            'rebuild' => projConcRebuildCode(),
            'producer' => projConcProducerDuringSnapshot($scenario['work']->value->value),
        ]);
    } finally {
        projConcDropTrigger();
    }
    $late = new ProcessUuid($results['producer']['event']);

    expect($results['rebuild'])->toBe(['status' => 'rebuilt'])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        // Snapshot зафиксировал committed события до producer: у позднего события receipt нет, fanout ждёт consumer.
        ->and(Fixture::receipts('work_transitions'))->toBe(3)
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $late->value)->exists())->toBeFalse()
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$late->value)->whereNull('published_at')->exists())->toBeTrue();

    expect(app(MetricProjector::class)->apply($late))->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(4)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'superseded')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('retention держит state FOR UPDATE, rebuild ждёт: prune выигрывает, rebuild отказывает HistoryUnavailable, источник удалён законно', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['succeeded']);
    projConcPublishFanout($scenario['succeeded']);

    $results = ConcurrentTransactions::concurrently([
        'prune' => projConcHold(projConcPruneCode($scenario['succeeded'])),
        'rebuild' => projConcAfter(projConcRebuildCode()),
    ]);

    expect($results['prune'])->toBe(['pruned' => 1, 'deferred' => false])
        ->and($results['rebuild'])->toBe(['status' => RebuildStatus::HistoryUnavailable->value])
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and((bool) projConcState()->history_complete)->toBeFalse()
        ->and(projConcState()->mode)->toBe('active')
        ->and(DB::table('prc_process_transitions')->where('event_id', $scenario['succeeded']->value)->exists())->toBeFalse()
        // Live totals не уменьшились из-за retention.
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('rebuild gate выигрывает у retention: prune откладывается целиком, источник и полнота истории остаются', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['succeeded']);
    projConcPublishFanout($scenario['succeeded']);
    $token = LedgerFixtures::uuid();

    $results = ConcurrentTransactions::concurrently([
        'gate' => projConcHold(projConcGateCode($token->value)),
        'prune' => projConcAfter(projConcPruneCode($scenario['succeeded'])),
    ]);

    expect($results['gate'])->toBe(['entered' => true])
        ->and($results['prune'])->toBe(['pruned' => 0, 'deferred' => true])
        ->and(DB::table('prc_process_transitions')->where('event_id', $scenario['succeeded']->value)->exists())->toBeTrue()
        ->and((bool) projConcState()->history_complete)->toBeTrue()
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $scenario['succeeded']->value)->exists())->toBeTrue();
});

test('сбой после записи первой серии новой generation: rollback целиком, прежняя generation читается, gate снят, повтор проходит', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);
    $receiptsBefore = Fixture::receipts('work_transitions');
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();
    projConcInstallTrigger("IF NEW.generation > 1 AND EXISTS (SELECT 1 FROM prc_process_metric_totals t WHERE t.projector_key = NEW.projector_key AND t.generation = NEW.generation) THEN RAISE EXCEPTION 'injected crash after the first new total'; END IF;");

    expect(fn () => app(ProjectionRebuilder::class)->rebuild('work_transitions'))->toThrow(QueryException::class);
    projConcDropTrigger();

    // Receipts новой generation, записанные в той же tx, откатились вместе с totals.
    expect(Fixture::generation('work_transitions'))->toBe(1)
        ->and(projConcState()->mode)->toBe('active')
        ->and(projConcState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt)
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(3);
});

test('процесс умер после шага A и не снял gate: gate виден, apply откладывается, resume только с token, totals не меняются', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();
    $token = LedgerFixtures::uuid();

    // Отдельный PHP-процесс коммитит gate и завершается без finally/release — как kill после шага A.
    $dead = ConcurrentTransactions::execute('return \Illuminate\Support\Facades\DB::transaction(function () { '.projConcGateCode($token->value).' });');

    expect($dead)->toBe(['entered' => true])
        ->and(projConcState()->mode)->toBe('maintenance')
        ->and(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Maintenance])
        ->and(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Busy)
        ->and(projConcState()->maintenance_token)->toBe($token->value)
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', LedgerFixtures::uuid()))->toBeFalse()
        ->and(projConcState()->mode)->toBe('maintenance')
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', $token))->toBeTrue()
        ->and(projConcState()->mode)->toBe('active')
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt)
        ->and(Fixture::generation('work_transitions'))->toBe(2);
});

/**
 * Настоящий SIGKILL worker-процесса без finally/shutdown (PHP-исключения и exit(0) их исполняют, kill -9 — нет).
 * Marker-файл доказывает, что процесс убит именно в заданной точке, а не упал по другой причине.
 */
function projConcSelfKill(string $marker, string $phase): string
{
    return str_replace(['__MARKER__', '__PHASE__'], [$marker, $phase], <<<'PHP'
        file_put_contents('__MARKER__', '__PHASE__');
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), 9);
        } else {
            exec('kill -9 '.getmypid());
        }
        $until = hrtime(true) + 10_000_000_000;
        while (hrtime(true) < $until) {
            usleep(10_000);
        }
        throw new \RuntimeException('self-kill did not terminate the worker');
        PHP);
}

/** Rebuild-процесс убивается сразу после первой записанной серии новой generation: tx открыта, flip не выполнен. */
function projConcKillDuringTransactionCode(string $marker): string
{
    return str_replace('__KILL__', projConcSelfKill($marker, 'during-transaction'), <<<'PHP'
        \Illuminate\Support\Facades\DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'INSERT INTO prc_process_metric_totals')) {
                __KILL__
            }
        });
        PHP).' '.projConcRebuildCode();
}

/** Rebuild-процесс убивается сразу после COMMIT публикации generation, до cleanup и возврата результата. */
function projConcKillAfterCommitCode(string $marker): string
{
    return str_replace('__KILL__', projConcSelfKill($marker, 'after-commit'), <<<'PHP'
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, static function (): void {
            $generation = (int) \Illuminate\Support\Facades\DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('active_generation');
            if ($generation < 2) {
                return;
            }
            __KILL__
        });
        PHP).' '.projConcRebuildCode();
}

/** Ключ advisory lock, на котором trigger держит rebuild внутри snapshot, пока producer не закоммитил. */
function projConcLateProducerKey(): int
{
    return 46150001;
}

/**
 * Producer: держит ключ, аллоцирует transition (uncommitted) ДО snapshot rebuild, сигналит старт rebuild,
 * ждёт, пока rebuild реально заблокирован trigger-ом внутри snapshot, коммитит и только затем отпускает ключ.
 */
function projConcLateProducerCode(string $workId): string
{
    return str_replace(['__WORK__', '__KEY__'], [$workId, (string) projConcLateProducerKey()], <<<'PHP'
        $fx = \Tests\Support\ProcessCore\FinalizeFixtures::class;
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(?)', [__KEY__]);
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $event = \Tests\Feature\ProcessCore\Observability\ProjectionFixtures::transition(
                new \App\Dto\Procurement\Process\WorkId(new \App\Dto\Procurement\Process\ProcessUuid('__WORK__')), 'running', null, 'admitted',
            );
            \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(?)', [$fx::SIGNAL]);
            $deadline = hrtime(true) + 10_000_000_000;
            while ((int) \Illuminate\Support\Facades\DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND wait_event = 'advisory'")->n < 1) {
                if (hrtime(true) > $deadline) {
                    throw new \RuntimeException('Process barrier timed out.');
                }
                usleep(5_000);
            }
            \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\DB::rollBack();

            throw $exception;
        }
        $committedAt = \Illuminate\Support\Facades\DB::selectOne('SELECT clock_timestamp()::text AS at')->at;
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(?)', [__KEY__]);
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(?)', [$fx::SIGNAL]);

        return ['event' => $event->value, 'committed_at' => $committedAt];
        PHP);
}

test('rebuild-процесс убит SIGKILL посреди transaction: прежняя generation и receipts целы, gate виден с token, resume снимает его без работы', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);
    $receiptsBefore = Fixture::receipts('work_transitions');
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();
    $outboxBefore = DB::table('prc_process_outbox')->count();
    $transitionsBefore = DB::table('prc_process_transitions')->count();
    $marker = (string) tempnam(sys_get_temp_dir(), 'p15-kill-');

    try {
        expect(fn () => ConcurrentTransactions::execute(projConcKillDuringTransactionCode($marker)))
            ->toThrow(RuntimeException::class, 'PostgreSQL worker failed');
        $phase = (string) file_get_contents($marker);
    } finally {
        @unlink($marker);
    }
    $token = projConcState()->maintenance_token;

    // finally процесса не выполнился: gate остаётся maintenance с token; незакоммиченная новая generation исчезла.
    expect($phase)->toBe('during-transaction')
        ->and($token)->not->toBeNull()
        ->and(projConcState()->mode)->toBe('maintenance')
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore)
        ->and(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Maintenance])
        ->and(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Busy)
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', LedgerFixtures::uuid()))->toBeFalse()
        ->and(app(ProjectionRebuilder::class)->resume('work_transitions', new ProcessUuid((string) $token)))->toBeTrue()
        ->and(projConcState()->mode)->toBe('active')
        // Recovery снимает gate и не запускает work/events/outbox.
        ->and(DB::table('prc_process_outbox')->count())->toBe($outboxBefore)
        ->and(DB::table('prc_process_transitions')->count())->toBe($transitionsBefore)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt)
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(3);
});

test('rebuild-процесс убит SIGKILL после commit до возврата: новая generation целиком активна, replay fanout не удваивает, следующий rebuild чистит остатки', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);
    $marker = (string) tempnam(sys_get_temp_dir(), 'p15-kill-');

    try {
        expect(fn () => ConcurrentTransactions::execute(projConcKillAfterCommitCode($marker)))
            ->toThrow(RuntimeException::class, 'PostgreSQL worker failed');
        $phase = (string) file_get_contents($marker);
    } finally {
        @unlink($marker);
    }

    // Commit публикации состоялся: reader видит целиком new generation, gate снят самим flip-ом.
    expect($phase)->toBe('after-commit')
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(projConcState()->mode)->toBe('active')
        ->and(projConcState()->maintenance_token)->toBeNull()
        ->and(projConcState()->rebuilt_at)->not->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe(3)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running')))->toBe(1)
        // Cleanup после commit не успел: totals неактивной generation остались, но active читает только new.
        ->and(DB::table('prc_process_metric_totals')->where('generation', 1)->count())->toBeGreaterThan(0);

    // Повтор source fanout после гибели команды не удваивает; событие после flip применяется к new generation.
    $late = DB::transaction(fn () => Fixture::transition($scenario['work'], 'superseded', 'input_invalidated', 'succeeded'));
    expect(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate])
        ->and(app(MetricProjector::class)->apply($scenario['running']))->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(app(MetricProjector::class)->apply($late))->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'superseded')))->toBe(1)
        ->and(Fixture::generation('work_transitions'))->toBe(2);

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt)
        ->and(Fixture::generation('work_transitions'))->toBe(3)
        ->and(DB::table('prc_process_metric_totals')->where('projector_key', 'work_transitions')->where('generation', '<', 3)->count())->toBe(0);
});

/**
 * Процесс «замирает» между последней клиентской проверкой deadline и COMMIT: событие committing приходит уже после
 * возврата closure (assertWithinDeadline пройден). Отдельное соединение ждёт исчезновения backend, пока основной
 * клиент не выполняет SQL и не возвращается к COMMIT. Без transaction_timeout этот барьер завершается ошибкой.
 */
function projConcPauseBeforeCommitCode(string $marker, bool $consumeDiagnostic): string
{
    return str_replace(['__MARKER__', '__CONSUME__'], [$marker, $consumeDiagnostic ? 'true' : 'false'], <<<'PHP'
        config(['process.projection.rebuild_deadline_seconds' => 2]);
        $connection = \Illuminate\Support\Facades\DB::connection();
        $beforePid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        // Существующая session policy не должна мешать rebuild взвести собственный короткий timer.
        $connection->statement('SET transaction_timeout = 10000');
        config(['database.connections.projection_timeout_observer' => $connection->getConfig()]);
        $observer = \Illuminate\Support\Facades\DB::connection('projection_timeout_observer');
        $observerPid = (int) $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $armed = true;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitting::class, static function ($event) use ($connection, $observer, $beforePid, &$armed): void {
            if (! $armed || $event->connection !== $connection) {
                return;
            }
            $settings = $connection->selectOne("SELECT current_setting('transaction_isolation') AS level, (SELECT setting::int FROM pg_settings WHERE name = 'transaction_timeout') AS timeout_ms");
            if ($settings->level !== 'repeatable read') {
                return;
            }
            $armed = false;
            $generation = (int) $connection->table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('active_generation');
            $idle = $observer->selectOne('SELECT state FROM pg_stat_activity WHERE datname = current_database() AND pid = ?', [$beforePid]);
            $proof = ['phase' => 'paused-before-commit', 'generation_in_transaction' => $generation, 'timeout_ms' => (int) $settings->timeout_ms, 'backend_state' => $idle?->state];
            file_put_contents('__MARKER__', json_encode($proof, JSON_THROW_ON_ERROR));
            $until = hrtime(true) + 8_000_000_000;
            while ($observer->selectOne('SELECT 1 FROM pg_stat_activity WHERE datname = current_database() AND pid = ?', [$beforePid]) !== null) {
                if (hrtime(true) > $until) {
                    throw new \RuntimeException('transaction_timeout did not terminate the idle rebuild backend');
                }
                usleep(10_000);
            }
            $proof['phase'] = 'backend-terminated-before-commit';
            file_put_contents('__MARKER__', json_encode($proof, JSON_THROW_ON_ERROR));
            if (__CONSUME__) {
                // Некоторые transport paths теряют FATAL diagnostic. Получаем оба реальных PDO failures:
                // первый читает server timeout, второй — уже потерянное соединение без 25P04.
                $pdo = $connection->getPdo();
                try {
                    $pdo->query('SELECT 1');
                } catch (\PDOException) {
                }
                $pdo->query('SELECT 1');
                throw new \RuntimeException('terminated PDO unexpectedly accepted a query');
            }
        });
        $result = app(\App\Services\Procurement\Process\ProjectionRebuilder::class)->rebuild('work_transitions');
        $afterPid = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $level = \Illuminate\Support\Facades\DB::transactionLevel();
        $timeoutMs = (int) \Illuminate\Support\Facades\DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'transaction_timeout'")->setting;
        \Illuminate\Support\Facades\DB::purge('projection_timeout_observer');

        return ['status' => $result->status->value, 'reconnected' => $afterPid !== $beforePid, 'independent_observer' => $observerPid !== $beforePid, 'transaction_level' => $level, 'timeout_ms_after_reconnect' => $timeoutMs];
        PHP);
}

test('пауза процесса после последней клиентской проверки перед COMMIT: сервер завершает tx по transaction_timeout — Timeout, flip не committed, gate снят', function (bool $consumeDiagnostic): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);
    $receiptsBefore = Fixture::receipts('work_transitions');
    $totalsBefore = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();
    $marker = (string) tempnam(sys_get_temp_dir(), 'p15-pause-');

    try {
        $result = ConcurrentTransactions::execute(projConcPauseBeforeCommitCode($marker, $consumeDiagnostic));
        $proof = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink($marker);
    }

    // Клиентские проверки пройдены до паузы; без серверного transaction_timeout COMMIT состоялся бы и вернул rebuilt.
    expect($proof['phase'])->toBe('backend-terminated-before-commit')
        ->and($proof['generation_in_transaction'])->toBe(2)
        ->and($proof['backend_state'])->toBe('idle in transaction')
        ->and($proof['timeout_ms'])->toBeGreaterThan(0)->toBeLessThanOrEqual(2000)
        ->and($result)->toBe(['status' => RebuildStatus::Timeout->value, 'reconnected' => true, 'independent_observer' => true, 'transaction_level' => 0, 'timeout_ms_after_reconnect' => 0])
        ->and(Fixture::generation('work_transitions'))->toBe(1)
        ->and(projConcState()->mode)->toBe('active')
        ->and(projConcState()->maintenance_token)->toBeNull()
        ->and(projConcState()->rebuilt_at)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe($receiptsBefore)
        ->and(DB::table('prc_process_metric_totals')->where('generation', 2)->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($totalsBefore);

    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt)
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(3);
})->with(['native COMMIT failure' => false, 'consumed server diagnostic' => true]);

test('потеря соединения после committed flip и deadline не объявляется Timeout: новая generation и receipts сохраняются', function (): void {
    $scenario = projConcScenario();
    app(MetricProjector::class)->apply($scenario['running']);

    $result = ConcurrentTransactions::execute(<<<'PHP'
        config(['process.projection.rebuild_deadline_seconds' => 2]);
        $connection = \Illuminate\Support\Facades\DB::connection();
        $pid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        config(['database.connections.projection_timeout_observer' => $connection->getConfig()]);
        $observer = \Illuminate\Support\Facades\DB::connection('projection_timeout_observer');
        $observer->getPdo();
        $started = hrtime(true);
        $armed = true;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, static function ($event) use ($connection, $observer, $pid, $started, &$armed): void {
            if (! $armed || $event->connection !== $connection) {
                return;
            }
            $generation = (int) $observer->table('prc_process_projection_states')->where('projector_key', 'work_transitions')->value('active_generation');
            if ($generation < 2) {
                return;
            }
            $armed = false;
            // COMMIT уже виден независимому reader; обрыв доставлен после абсолютного deadline.
            while (hrtime(true) - $started < 2_200_000_000) {
                usleep(10_000);
            }
            $observer->selectOne('SELECT pg_terminate_backend(?)', [$pid]);
            $connection->getPdo()->query('SELECT 1');
            throw new \RuntimeException('terminated PDO unexpectedly accepted a query');
        });
        try {
            $status = app(\App\Services\Procurement\Process\ProjectionRebuilder::class)->rebuild('work_transitions')->status->value;
        } catch (\PDOException) {
            $status = 'connection_lost';
        } finally {
            \Illuminate\Support\Facades\DB::purge('projection_timeout_observer');
        }

        return ['status' => $status, 'committed_flip_observed' => ! $armed];
        PHP);

    expect($result)->toBe(['status' => 'connection_lost', 'committed_flip_observed' => true])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(projConcState()->mode)->toBe('active')
        ->and(projConcState()->maintenance_token)->toBeNull()
        ->and(Fixture::receipts('work_transitions'))->toBe(3)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(app(MetricProjector::class)->apply($scenario['succeeded']))->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate]);
});

test('заранее аллоцированный lower-seq event committed ПОСЛЕ snapshot, но ДО flip: без receipt в snapshot, после flip применяется ровно один раз', function (): void {
    $scenario = projConcScenario();
    // Другая work: её transition seq=2 аллоцирован раньше (и ниже) seq=3 уже committed события первой work.
    $other = DB::transaction(fn (): WorkId => Fixture::workInRun()['work']);
    // Trigger держит rebuild внутри snapshot на первой записи серии, пока producer не отпустит ключ после commit.
    projConcInstallTrigger('PERFORM pg_advisory_lock('.projConcLateProducerKey().'); PERFORM pg_advisory_unlock('.projConcLateProducerKey().');');

    try {
        $results = ConcurrentTransactions::concurrently([
            'producer' => projConcLateProducerCode($other->value->value),
            'rebuild' => projConcAfter(projConcRebuildCode()),
        ]);
    } finally {
        projConcDropTrigger();
    }
    $late = new ProcessUuid($results['producer']['event']);

    expect($results['rebuild'])->toBe(['status' => 'rebuilt'])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        // Producer закоммитил строго до публикации generation: иначе гонка «commit после flip» не проверялась бы.
        ->and(DB::selectOne('SELECT ?::timestamptz < ?::timestamptz AS ordered', [$results['producer']['committed_at'], projConcState()->rebuilt_at])->ordered)->toBeTrue()
        ->and((int) DB::table('prc_process_transitions')->where('event_id', $late->value)->value('entity_seq'))->toBe(2)
        ->and((int) DB::table('prc_process_transitions')->where('work_id', $scenario['work']->value->value)->max('entity_seq'))->toBe(3)
        // Snapshot: 3 события первой work + создание второй; late committed после него и receipt не получил.
        ->and(Fixture::receipts('work_transitions'))->toBe(4)
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $late->value)->exists())->toBeFalse()
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$late->value)->whereNull('published_at')->exists())->toBeTrue()
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running')))->toBe(1);

    expect(app(MetricProjector::class)->apply($late))->toBe(['work_transitions' => ProjectionApplyStatus::Applied])
        ->and(app(MetricProjector::class)->apply($late))->toBe(['work_transitions' => ProjectionApplyStatus::Duplicate])
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(5)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'running')))->toBe(2)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('cleanup неактивных generation ждёт идущий apply под state lock и не трогает active totals', function (): void {
    $scenario = projConcScenario();
    expect(app(ProjectionRebuilder::class)->rebuild('work_transitions')->status)->toBe(RebuildStatus::Rebuilt);
    DB::table('prc_process_metric_totals')->insert([
        'projector_key' => 'work_transitions', 'generation' => 1, 'metric_key' => 'work_transition_total',
        'dimension_key' => Fixture::workDimension('listing', 'succeeded'), 'value' => 5, 'updated_at' => DB::raw('clock_timestamp()'),
    ]);
    $late = DB::transaction(fn () => Fixture::transition($scenario['work'], 'superseded', 'input_invalidated', 'succeeded'));

    $results = ConcurrentTransactions::concurrently([
        'apply' => projConcHold(projConcApplyCode($late)),
        'cleanup' => projConcAfter(
            "return \\Illuminate\\Support\\Facades\\DB::transaction(function () { \$repository = app(\\App\\Repositories\\Procurement\\Process\\ProjectionRepository::class); \$repository->stateForUpdate('work_transitions'); return ['deleted' => \$repository->deleteInactiveTotals('work_transitions')]; });",
        ),
    ]);

    expect($results['apply'])->toBe(['work_transitions' => 'applied'])
        ->and($results['cleanup'])->toBe(['deleted' => 1])
        ->and(DB::table('prc_process_metric_totals')->where('projector_key', 'work_transitions')->where('generation', 1)->count())->toBe(0)
        ->and(Fixture::generation('work_transitions'))->toBe(2)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'superseded')))->toBe(1)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

/** Prune двух событий в отдельном процессе; first DELETE ledger event уже выполнен, когда listener убивает процесс. */
function projConcKillDuringPruneCode(string $marker, ProcessUuid $first, ProcessUuid $second): string
{
    return str_replace('__KILL__', projConcSelfKill($marker, 'during-prune'), <<<'PHP'
        \Illuminate\Support\Facades\DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'DELETE FROM prc_process_transitions')) {
                __KILL__
            }
        });
        PHP)." \$result = app(\\App\\Services\\Procurement\\Process\\ProjectionRetention::class)->prune([new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$first->value}'), new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$second->value}')]); return ['pruned' => \$result->pruned];";
}

/** @return array{first: ProcessUuid, second: ProcessUuid} события с receipt и подтверждённым fanout, готовые к prune */
function projConcPrunable(): array
{
    $scenario = projConcScenario();
    foreach ([$scenario['running'], $scenario['succeeded']] as $event) {
        app(MetricProjector::class)->apply($event);
        projConcPublishFanout($event);
    }

    return ['first' => $scenario['running'], 'second' => $scenario['succeeded']];
}

test('retention: SIGKILL после первого DELETE ledger event откатывает весь batch; история полна; повтор prune завершает, затем no-op', function (): void {
    $events = projConcPrunable();
    $marker = (string) tempnam(sys_get_temp_dir(), 'p118-kill-');

    try {
        expect(fn () => ConcurrentTransactions::execute(projConcKillDuringPruneCode($marker, $events['first'], $events['second'])))
            ->toThrow(RuntimeException::class, 'PostgreSQL worker failed');
        $phase = (string) file_get_contents($marker);
    } finally {
        @unlink($marker);
    }

    // finally процесса не выполнился; PostgreSQL откатил tx при потере соединения: ничего не удалено, history_complete не тронут.
    expect($phase)->toBe('during-prune')
        ->and(DB::table('prc_process_transitions')->whereIn('event_id', [$events['first']->value, $events['second']->value])->count())->toBe(2)
        ->and(DB::table('prc_process_projection_receipts')->whereIn('event_id', [$events['first']->value, $events['second']->value])->count())->toBe(2)
        ->and((bool) projConcState()->history_complete)->toBeTrue()
        ->and(projConcState()->history_pruned_at)->toBeNull()
        ->and(projConcState()->mode)->toBe('active');

    $retry = app(ProjectionRetention::class)->prune([$events['first'], $events['second']]);
    expect([$retry->pruned, $retry->ineligible])->toBe([2, 0])
        ->and(DB::table('prc_process_transitions')->whereIn('event_id', [$events['first']->value, $events['second']->value])->count())->toBe(0)
        ->and((bool) projConcState()->history_complete)->toBeFalse()
        // Live totals не уменьшились; replay уже удалённого события безопасен.
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1)
        ->and(app(ProjectionRetention::class)->prune([$events['first'], $events['second']]))->toEqual(new PruneResult(0, 0, false));
});

test('retention: сбой при пометке history_complete откатывает удаления целиком; повтор после устранения проходит', function (): void {
    $events = projConcPrunable();
    DB::unprepared("CREATE FUNCTION prc_test_prune_trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected crash before history mark'; END \$\$");
    DB::unprepared('CREATE TRIGGER prc_test_prune_trigger BEFORE UPDATE ON prc_process_projection_states FOR EACH ROW EXECUTE FUNCTION prc_test_prune_trigger()');

    try {
        expect(fn () => app(ProjectionRetention::class)->prune([$events['first'], $events['second']]))->toThrow(QueryException::class);
    } finally {
        projConcDropTrigger();
    }

    expect(DB::table('prc_process_transitions')->whereIn('event_id', [$events['first']->value, $events['second']->value])->count())->toBe(2)
        ->and(DB::table('prc_process_projection_receipts')->whereIn('event_id', [$events['first']->value, $events['second']->value])->count())->toBe(2)
        ->and((bool) projConcState()->history_complete)->toBeTrue();

    expect(app(ProjectionRetention::class)->prune([$events['first'], $events['second']])->pruned)->toBe(2)
        ->and((bool) projConcState()->history_complete)->toBeFalse();
});

test('retention: apply события держит state FOR SHARE, prune ждёт и после появления receipt видит неподтверждённый fanout — источник остаётся', function (): void {
    $scenario = projConcScenario();
    $prune = "\$result = app(\\App\\Services\\Procurement\\Process\\ProjectionRetention::class)->prune([new \\App\\Dto\\Procurement\\Process\\ProcessUuid('{$scenario['succeeded']->value}')]); return ['pruned' => \$result->pruned, 'ineligible' => \$result->ineligible];";

    // Новая «ссылка» (receipt) появляется одновременно с удалением; fanout ещё не подтверждён relay/projector.
    $results = ConcurrentTransactions::concurrently([
        'apply' => projConcHold(projConcApplyCode($scenario['succeeded'])),
        'prune' => projConcAfter($prune),
    ]);

    expect($results['apply'])->toBe(['work_transitions' => 'applied'])
        ->and($results['prune'])->toBe(['pruned' => 0, 'ineligible' => 1])
        ->and(DB::table('prc_process_transitions')->where('event_id', $scenario['succeeded']->value)->exists())->toBeTrue()
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $scenario['succeeded']->value)->exists())->toBeTrue()
        ->and((bool) projConcState()->history_complete)->toBeTrue()
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});
