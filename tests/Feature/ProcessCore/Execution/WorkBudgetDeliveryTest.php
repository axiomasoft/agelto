<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('transport-and-work-budget', 'claim-recovery-and-invariants');

// item и handler-fault budgets одного work через настоящий путь delivery → WorkRunner →
// claim → handler → единственный WorkFinalizer, а не прямые вызовы finalizer. Handler исполняет
// заданный сценарий по шагам и считает вызовы: ноль новых вызовов — ноль handler/send.

/**
 * Listing authority и fixture handler, исполняющий шаги сценария по порядку.
 *
 * @param  list<Closure(ExecutionContext): StageOutcome>  $script
 */
function budgetHandler(array $script): object
{
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        /** @var list<Closure(ExecutionContext): StageOutcome> */
        public array $script = [];

        public int $calls = 0;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $step = $this->script[$this->calls++] ?? throw new LogicException('Delivery beyond the scenario executed the handler.');

            return $step($execution);
        }
    };
    $handler->script = $script;
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);

    return $handler;
}

function budgetEnvelope(WorkId $work): DeliveryEnvelope
{
    return DeliveryEnvelope::fromScalars($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value, 1);
}

/** Следующая delivery: due наступил (ход времени), настоящий feeder admits, runner исполняет текущее поколение. */
function budgetDeliver(WorkId $work): DeliveryOutcome
{
    DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE id = ? AND state = 'deferred'", [$work->value->value]);
    app(WorkFeeder::class)->tick('acquisition');

    return app(WorkRunner::class)->runDelivery(budgetEnvelope($work));
}

/** @return array{0: string, 1: string|null, 2: int, 3: int|null} state, reason, item_failures, due через N s */
function budgetRow(WorkId $work): array
{
    $row = DB::selectOne(
        'SELECT state, reason_code, item_failures, extract(epoch FROM due_at - clock_timestamp()) AS due_in FROM prc_process_work WHERE id = ?',
        [$work->value->value],
    );

    return [$row->state, $row->reason_code, $row->item_failures, $row->due_in === null ? null : (int) round((float) $row->due_in)];
}

function budgetFaults(WorkId $work): int
{
    return DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->whereNotNull('finished_at')->count();
}

test('item: три typed rejects одного work_key через WorkRunner → failed_item; transport deferrals между ними не расходуют item budget; следующая delivery не вызывает handler', function (): void {
    $reject = static fn (): StageOutcome => new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'evidence/reject', LedgerFixtures::hash('reject')));
    $transport = static fn (DeferralReason $reason): Closure => static fn (): StageOutcome => new StageDeferred(
        $reason,
        new RetryAt(app(ProcessClock::class)->dbNow()->value->modify('+20 seconds')),
    );
    $handler = budgetHandler([$transport(DeferralReason::RateLimit), $reject, $transport(DeferralReason::NoRoute), $reject, $reject]);
    $work = ExecutionFixtures::listingWork();

    $observed = [];
    foreach (range(1, 5) as $delivery) {
        expect(budgetDeliver($work))->toBe(DeliveryOutcome::Executed);
        $observed[] = budgetRow($work);
    }

    expect(array_map(static fn (array $row): array => array_slice($row, 0, 3), $observed))->toBe([
        ['deferred', 'rate_limit', 0],
        ['deferred', 'item_retry', 1],
        ['deferred', 'no_route', 1],
        ['deferred', 'item_retry', 2],
        ['failed_item', 'contract_rejected', 3],
    ])
        // Item retry schedule считает только typed rejects: +30 s, +120 s, затем terminal без due.
        ->and([$observed[1][3], $observed[3][3], $observed[4][3]])->toBe([30, 120, null]);

    // Delivery после exhaustion: terminal work, ни handler, ни attempt, ни admission.
    expect(app(WorkRunner::class)->runDelivery(budgetEnvelope($work)))->toBe(DeliveryOutcome::Rejected)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and($handler->calls)->toBe(5)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe(5)
        ->and(budgetFaults($work))->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});

test('один input через WorkRunner: faults 1/2 → deferred(handler_fault) +60 s, fault 3 → blocked(handler_fault_limit); journal rollback и lease interruption budget не расходуют; следующая delivery не вызывает handler', function (): void {
    $fault = static fn (): StageOutcome => throw new LogicException('fixture programming fault');
    // Journal insert finalize падает (занятый entity_seq): весь finishHandlerFault откатывается.
    $journalConflict = static function (ExecutionContext $execution): StageOutcome {
        $id = $execution->lease()->workId->value->value;
        DB::insert(
            "INSERT INTO prc_process_transitions (event_id, entity_kind, work_id, entity_seq, new_state, actor_kind, schema_version) VALUES (?, 'work', ?, ?, 'blocker', 'system', 1)",
            [LedgerFixtures::uuid()->value, $id, (int) DB::table('prc_process_work')->where('id', $id)->value('transition_seq') + 1],
        );

        throw new LogicException('fixture programming fault');
    };
    $handler = budgetHandler([$fault, $journalConflict, $fault, $fault]);
    $work = ExecutionFixtures::listingWork();
    $id = $work->value->value;

    expect(budgetDeliver($work))->toBe(DeliveryOutcome::Executed);
    [$state, $reason, $items, $due] = budgetRow($work);
    expect([$state, $reason, $items, budgetFaults($work)])->toBe(['deferred', 'handler_fault', 0, 1])
        ->and($due)->toBeGreaterThanOrEqual(59)->toBeLessThanOrEqual(66);

    // Нетранзиентная SQL ошибка finalize видима; fault не записан, work остаётся durable running.
    expect(fn () => budgetDeliver($work))->toThrow(QueryException::class)
        ->and([ExecutionFixtures::work($work)->state, budgetFaults($work)])->toBe(['running', 1])
        ->and(DB::table('prc_process_attempts')->where('work_id', $id)->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0);
    DB::delete("DELETE FROM prc_process_transitions WHERE work_id = ? AND new_state = 'blocker'", [$id]);

    // Lease interruption (recovery) — не programming fault: attempt interrupted без signature.
    FinalizeFixtures::expire($work);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and([ExecutionFixtures::work($work)->state, budgetFaults($work)])->toBe(['ready', 1]);

    expect(budgetDeliver($work))->toBe(DeliveryOutcome::Executed)
        ->and([...array_slice(budgetRow($work), 0, 3), budgetFaults($work)])->toBe(['deferred', 'handler_fault', 0, 2])
        ->and(budgetDeliver($work))->toBe(DeliveryOutcome::Executed)
        ->and([...budgetRow($work), budgetFaults($work)])->toBe(['blocked', 'handler_fault_limit', 0, null, 3]);
    $incident = DB::table('prc_process_incidents')->sole();
    expect([$incident->kind, $incident->state, $incident->scope_key])->toBe(['handler_fault_limit', 'open', 'work:'.$id])
        ->and(DB::table('prc_process_work')->where('id', $id)->value('provider_recovery_due_at'))->toBeNull()
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);

    // Четвёртая delivery, feeder tick, generic resume и scanners не исполняют и не освобождают work.
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    $reconciler = app(RecoveryReconciler::class);
    expect(app(WorkRunner::class)->runDelivery(budgetEnvelope($work)))->toBe(DeliveryOutcome::Rejected)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and($reconciler->releaseBlocked())->toBe(0)
        ->and($reconciler->recoverAdmitted())->toBe(0)
        ->and($handler->calls)->toBe(4)
        ->and(DB::table('prc_process_attempts')->where('work_id', $id)->count())->toBe(4)
        ->and(DB::table('prc_process_attempts')->where('work_id', $id)->where('terminal_kind', 'interrupted')->whereNull('fault_signature')->count())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('blocked')
        // Один input не набирает distinct-input порог: глобальный hold остаётся независимым.
        ->and($reconciler->sweepHandlerFaults())->toBe(3)
        ->and(DB::table('prc_process_handler_holds')->where('stage', 'listing')->value('state'))->toBe('healthy');
});
