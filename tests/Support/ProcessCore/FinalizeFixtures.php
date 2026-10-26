<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\HandlerFaultEvidence;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\LockedPrefix;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixture finalize/lease (P1.8). До P1.11 нет настоящего RecoveryReconciler, до P1.17 — CancelWork:
 * revoke() — контролируемая generation revocation под тем же полным prefix, а не fake recovery
 * evidence. Raw SQL лишь моделирует ход времени (истёкший lease, наступивший due). Barriers
 * упорядочивают реальные PostgreSQL connections без sleep: holder держит locks своей операции до
 * тех пор, пока другой backend реально не ждёт lock.
 */
final class FinalizeFixtures
{
    /** Ключ session advisory lock, которым holder сигнализирует «мои locks взяты». */
    public const int SIGNAL = 818_001;

    /**
     * Authority rows, fixture handlers и finalization binding stages.
     *
     * @param  list<StageCode>  $stages
     * @param  array<string, array<string, int>>  $lanes
     * @return array<string, FixtureStageResultRepository>
     */
    public static function authority(array $stages, array $lanes = []): array
    {
        ExecutionFixtures::authority($stages, $lanes);
        $repositories = [];
        foreach ($stages as $stage) {
            $repositories[$stage->value] = self::bind($stage);
        }

        return $repositories;
    }

    /** In-memory registry binding stage этого PHP процесса (нужен и в workers). */
    public static function bind(StageCode $stage): FixtureStageResultRepository
    {
        ExecutionFixtures::registerHandler($stage);
        $repository = new FixtureStageResultRepository;
        app(StageRegistry::class)->bindFinalization($stage, new FixtureStagePayloadCodec($stage), $repository);

        return $repository;
    }

    /** Admission parent lane и claim текущей delivery: lease живого owner. */
    public static function running(WorkId $work): ExecutionLease
    {
        $stage = StageCode::from((string) DB::table('prc_process_work')->where('id', $work->value->value)->value('stage'));
        app(WorkFeeder::class)->tick(app(ProcessPolicy::class)->parentLane($stage));
        $result = app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));

        return $result->lease ?? throw new \LogicException('Fixture work was not claimed: '.$result->status->value);
    }

    /** Deferred work, чей due наступил (ход времени), снова admitted и claimed. */
    public static function rerun(WorkId $work): ExecutionLease
    {
        DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE id = ? AND state = 'deferred'", [$work->value->value]);

        return self::running($work);
    }

    /** DB lease истёк (ход времени); recovery ещё не пришла. */
    public static function expire(WorkId $work): void
    {
        DB::update("UPDATE prc_process_work SET lease_until = clock_timestamp() - interval '1 second' WHERE id = ? AND state = 'running'", [$work->value->value]);
    }

    /**
     * Контролируемая generation revocation под полным prefix control → handler → capacity →
     * subject → work: recovery истёкшего lease (→ ready, attempt interrupted) либо hard cancel
     * (→ cancelled). Terminal work не переписывается; renewed lease recovery не трогает.
     */
    public static function revoke(WorkId $work, bool $cancel = false): bool
    {
        $authority = app(AuthorityRepository::class);
        $snapshot = $authority->readAuthoritySnapshot($work);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new \LogicException('Fixture work has no authority snapshot.');
        }

        return app(ProcessTransaction::class)->run(static function () use ($authority, $snapshot, $work, $cancel): bool {
            $prefix = $authority->lockPrefix($snapshot, skipLockedWork: false);
            if (! $prefix instanceof LockedPrefix) {
                throw new \LogicException('Fixture revocation could not lock the full prefix.');
            }
            if ($prefix->work->string('state') !== 'running' || (! $cancel && ! $prefix->work->bool('lease_expired'))) {
                return false;
            }
            $state = $cancel ? 'cancelled' : 'ready';
            $row = LedgerRow::one(
                "UPDATE prc_process_work
                 SET state = ?, reason_code = ?, generation = generation + 1, owner_token = NULL, lease_until = NULL,
                     checkpoint = CASE WHEN ?::int = 1 THEN NULL ELSE checkpoint END,
                     checkpoint_schema_version = CASE WHEN ?::int = 1 THEN NULL ELSE checkpoint_schema_version END,
                     infrastructure_interruptions = infrastructure_interruptions + ?, transition_seq = transition_seq + 1,
                     updated_at = clock_timestamp()
                 WHERE id = ? AND generation = ? AND state = 'running'
                 RETURNING generation, transition_seq",
                [$state, $cancel ? 'cancelled' : null, (int) $cancel, (int) $cancel, $cancel ? 0 : 1, $work->value->value, $prefix->work->int('generation')],
            ) ?? throw new \LogicException('Fixture revocation fence did not match.');
            DB::update(
                'UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = ? WHERE work_id = ? AND generation = ? AND finished_at IS NULL',
                [$cancel ? 'cancelled' : 'interrupted', $work->value->value, $prefix->work->int('generation')],
            );
            if ($prefix->group !== null) {
                app(WorkStoreRepository::class)->releaseGroupLocked($prefix->group, count($prefix->members), $cancel ? 'cancelled' : 'lease_recovery');
            }
            app(TransitionRepository::class)->recordWork(
                LedgerFixtures::uuid(), LedgerFixtures::uuid(), $work->value, $row->int('transition_seq'),
                'running', $state, $cancel ? 'cancelled' : 'lease_recovery', $cancel ? ActorKind::Operator : ActorKind::Reconciler, $row->int('generation'),
            );

            return true;
        });
    }

    public static function succeeded(StageCode $stage, string $key): StageSucceeded
    {
        return new StageSucceeded(new FixtureStageResult(new ArtifactRef('fixture', 'results/'.$key, LedgerFixtures::hash('result:'.$key)), $stage));
    }

    public static function fault(string $site): HandlerFaultEvidence
    {
        return new HandlerFaultEvidence(LedgerFixtures::hash('fault:'.$site), \LogicException::class);
    }

    /** Lease для передачи в worker process (scalar boundary тестов, не queue payload). */
    public static function export(ExecutionLease $lease): string
    {
        return base64_encode(serialize($lease));
    }

    public static function import(string $lease): ExecutionLease
    {
        $value = unserialize(base64_decode($lease, true) ?: '', ['allowed_classes' => true]);

        return $value instanceof ExecutionLease ? $value : throw new \LogicException('Invalid exported lease.');
    }

    /**
     * Barrier holder: операция внутри открытой tx держит свои locks; сигнал; commit только когда
     * другой backend реально ждёт lock этой tx.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    public static function holdUntilWaiter(\Closure $operation): mixed
    {
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::selectOne('SELECT pg_advisory_lock(?)', [self::SIGNAL]);
            self::await(static fn (): bool => (LedgerRow::one(
                "SELECT count(*) AS waiting FROM pg_stat_activity
                 WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'",
            )?->int('waiting') ?? 0) > 0);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
        DB::selectOne('SELECT pg_advisory_unlock(?)', [self::SIGNAL]);

        return $result;
    }

    /**
     * Barrier waiter: операция стартует только после сигнала holder и потому ждёт его locks.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    public static function afterHolder(\Closure $operation): mixed
    {
        self::await(static fn (): bool => (LedgerRow::one(
            "SELECT count(*) AS held FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND granted",
            [self::SIGNAL],
        )?->int('held') ?? 0) > 0);

        return $operation();
    }

    /** @param \Closure(): bool $condition */
    private static function await(\Closure $condition): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        while (! $condition()) {
            if (hrtime(true) > $deadline) {
                throw new \RuntimeException('Process barrier timed out.');
            }
            usleep(5_000);
        }
    }
}
