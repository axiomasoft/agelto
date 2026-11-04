<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ControlChangeResult;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Fixture handlers и реальные ledger rows для admission/claim. Handler — только
 * зарегистрированная fixture version; реальные acquisition/AI handlers не активируются. Raw SQL
 * здесь лишь моделирует факты будущих владельцев (возраст work, истёкшая delivery, held handler
 * detector ), а не обходит API исполнения.
 */
final class ExecutionFixtures
{
    public const array HANDLERS = [
        'listing' => 'fixture-listing/1',
        'detail' => 'fixture-detail/1',
        'status_refresh' => 'fixture-status/1',
        'download' => 'fixture-download/1',
        'prepare' => 'fixture-prepare/1',
        'screening' => 'fixture-screening/1',
    ];

    /** Ledger tables, которые многопроцессные tests очищают TRUNCATE (только isolated agelto_test). */
    private const array LEDGER = [
        'prc_process_outbox', 'prc_process_transitions', 'prc_process_run_work', 'prc_process_reservations',
        'prc_admission_groups', 'prc_process_attempts', 'prc_process_work', 'prc_operation_intents', 'prc_crawl_blocks',
        'prc_crawl_runs', 'prc_process_handler_holds', 'prc_process_incidents', 'prc_process_runs', 'prc_process_lanes',
        'prc_control_scopes', 'prc_process_daemon_heartbeats',
    ];

    /**
     * Policy lanes (опционально переопределённые), authority rows и fixture handler versions.
     *
     * @param  list<StageCode>  $stages
     * @param  array<string, array<string, int>>  $lanes  lane key → изменённые limits
     */
    public static function authority(array $stages, array $lanes = []): void
    {
        if ($lanes !== []) {
            /** @var array<string, array<string, mixed>> $policy */
            $policy = config('process.lanes');
            foreach ($lanes as $key => $limits) {
                $policy[$key] = array_replace($policy[$key], $limits);
            }
            config()->set('process.lanes', $policy);
        }
        $sync = app(ProcessAuthoritySync::class);
        $sync->ensureBaseline();
        foreach ($stages as $stage) {
            self::registerHandler($stage);
            $sync->registerHandler($stage, self::handlerVersion($stage));
        }
    }

    /** In-memory registry fixture handler; нужна в каждом PHP процессе, включая workers. */
    public static function registerHandler(StageCode $stage): void
    {
        app(StageRegistry::class)->register($stage, self::handlerVersion($stage));
    }

    /** Fixture handler существует только для schema-supported stages. */
    public static function handlerVersion(StageCode $stage): string
    {
        return self::HANDLERS[$stage->value] ?? throw new \InvalidArgumentException('No fixture handler for '.$stage->value);
    }

    /** Listing work на собственном реальном crawl block; транзакция открывается при необходимости. */
    public static function listingWork(string $cohort = 'manual', int $priority = 100): WorkId
    {
        return self::inTransaction(static function () use ($cohort, $priority): WorkId {
            $subject = LedgerFixtures::crawlBlock();

            return self::store(LedgerFixtures::listingInput($subject['block'], $subject['filter_hash'], $subject['access_policy_hash']), $cohort, $priority);
        });
    }

    /** Detail work с active intent на реальном procurement (factory; только RefreshDatabase tests). */
    public static function detailWork(string $cohort = 'manual', int $priority = 100): WorkId
    {
        $procurement = Procurement::factory()->create();
        $intent = LedgerFixtures::uuid();
        app(WorkStoreRepository::class)->storeIntent(
            id: $intent,
            intentKey: LedgerFixtures::hash('intent:'.$intent->value),
            stage: StageCode::Detail,
            subject: new SubjectKey(SubjectKind::Procurement, $procurement->id),
            semanticPolicyHash: LedgerFixtures::hash('semantic'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );

        return self::store(LedgerFixtures::detailInput($procurement->id, $intent), $cohort, $priority);
    }

    public static function statusWork(string $cohort = 'manual', int $priority = 100): WorkId
    {
        return self::store(LedgerFixtures::statusInput(Procurement::factory()->create()->id), $cohort, $priority);
    }

    public static function store(WorkInput $input, string $cohort, int $priority): WorkId
    {
        $id = new WorkId(LedgerFixtures::uuid());
        app(WorkStoreRepository::class)->storeWork(
            id: $id,
            input: $input,
            inputRevision: 1,
            intentId: null,
            cohortKey: $cohort,
            platformId: null,
            originRunId: null,
            priority: $priority,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        );

        return $id;
    }

    /** Моделирует work, созданную $seconds назад: age считается по immutable created_at. */
    public static function age(WorkId $work, int $seconds): void
    {
        DB::update('UPDATE prc_process_work SET created_at = clock_timestamp() - make_interval(secs => ?) WHERE id = ?', [$seconds, $work->value->value]);
    }

    /** Held handler version с incident: состояние, которое создаёт fault detector. */
    public static function holdHandler(StageCode $stage): void
    {
        self::inTransaction(static function () use ($stage): void {
            $incident = LedgerFixtures::uuid()->value;
            DB::insert(
                "INSERT INTO prc_process_incidents (id, fingerprint, scope_key, kind, state, first_seen_at, last_seen_at, evidence, evidence_schema_version)
                 VALUES (?, decode(?, 'hex'), ?, 'handler_hold', 'open', clock_timestamp(), clock_timestamp(), '{}', 1)",
                [$incident, LedgerFixtures::hash('hold:'.$incident)->hex, 'handler:'.$stage->value],
            );
            DB::update(
                "UPDATE prc_process_handler_holds SET state = 'held', version = version + 1, fault_signature = decode(?, 'hex'),
                   incident_id = ?, held_at = clock_timestamp() WHERE stage = ? AND handler_version = ?",
                [LedgerFixtures::hash('signature')->hex, $incident, $stage->value, self::handlerVersion($stage)],
            );
        });
    }

    /** Scopes closure work — как их создаёт feeder отдельной ensureScopes tx до admission. */
    public static function ensureWorkScopes(StageCode $stage, string $cohort): void
    {
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes(app(ProcessPolicy::class)->workScopes($stage, $cohort)));
    }

    public static function setMode(string $scopeKey, ControlMode $mode): ControlChangeResult
    {
        $version = LedgerRow::one('SELECT control_version FROM prc_control_scopes WHERE scope_key = ?', [$scopeKey])?->int('control_version') ?? 0;

        return app(ProcessTransaction::class)->run(fn (): ControlChangeResult => app(ControlRepository::class)->setMode(
            scopeKey: $scopeKey,
            mode: $mode,
            changedBy: null,
            reason: 'fixture '.$mode->value,
            expectedVersion: $version,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        ));
    }

    public static function delivery(WorkId $work): DeliveryGeneration
    {
        $row = LedgerRow::one('SELECT dispatch_generation FROM prc_process_work WHERE id = ?', [$work->value->value])
            ?? throw new \LogicException('Fixture work is missing.');

        return new DeliveryGeneration($row->int('dispatch_generation'));
    }

    /** @return object{state: string, generation: int, dispatch_generation: int, transition_seq: int, reason_code: string|null} */
    public static function work(WorkId $work): object
    {
        /** @var object{state: string, generation: int, dispatch_generation: int, transition_seq: int, reason_code: string|null} */
        return DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    }

    /**
     * Active reservation rows lane: authoritative W (все) и C (running).
     *
     * @return array{outstanding: int, running: int}
     */
    public static function laneCounts(string $laneKey): array
    {
        $row = LedgerRow::one(
            "SELECT count(r.lane_id) AS outstanding, count(r.lane_id) FILTER (WHERE r.state = 'running') AS running
             FROM prc_process_lanes l LEFT JOIN prc_process_reservations r ON r.lane_id = l.id WHERE l.lane_key = ?",
            [$laneKey],
        ) ?? throw new \LogicException('Aggregate query always returns a row.');

        return ['outstanding' => $row->int('outstanding'), 'running' => $row->int('running')];
    }

    public static function hash(string $seed): ContentHash
    {
        return LedgerFixtures::hash($seed);
    }

    /** Очищает весь Process ledger isolated test DB после многопроцессного теста. */
    public static function truncateLedger(): void
    {
        DB::statement('TRUNCATE '.implode(', ', self::LEDGER).' CASCADE');
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private static function inTransaction(\Closure $callback): mixed
    {
        return DB::connection()->transactionLevel() > 0 ? $callback() : DB::transaction($callback);
    }
}
