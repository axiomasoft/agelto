<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Governance\AuthorityGap;
use App\Enums\Procurement\Process\Outcomes\RecoveryOutcome;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\ProviderLinkageUnavailable;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Repositories\Procurement\Process\Projection\RecoveryRepository;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Diagnostics\InvariantScan;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Container\Attributes\Singleton;

/**
 * Единственный recovery owner: expired running и expired/paused
 * admitted, release blocked(paused|handler_hold), независимая категория blocked
 * provider_result_unknown и historical handler-fault sweep. Каждая категория — bounded batch (<=50),
 * для каждого work отдельная ProcessTransaction под полным prefix с повтором locked predicates;
 * изменившийся snapshot — rollback и новый pre-read (не более трёх раз), мёртвая delivery/Redis
 * здесь не участвуют. Pre-read provider facts (object I/O) выполняется вне DB locks, под locks
 * resolver только пишет settlement. Resolver provider operations composition root подключает
 * через bindProviderResolver до admission соответствующей stage.
 */
#[Singleton]
final class RecoveryReconciler
{
    public const int BATCH = 50;

    public const int CANCELLED_PROVIDER_QUOTA = 25;

    /** Snapshot менялся на каждой попытке: work остаётся durable, следующий tick повторит. */
    private const int MAX_RELOADS = 3;

    private ProviderRecoveryResolver $provider;

    /** First isolated failure of the current daemon category, released after its callback. */
    private ?\Throwable $workFailure = null;

    /** @var array{sort: string, id: string}|null */
    private ?array $expiredRunningCursor = null;

    /**
     * Id expired running, которые этот инстанс не забрал: DependencyUnavailable либо throwable
     * из isolated. Не больше BATCH. Следующий проход пробует их один раз до страницы курсора.
     * Курсор полной страницы не откатывается, постоянный сбой не держит хвост.
     * ProviderLinkageUnavailable не запоминается: incident уже открыт.
     *
     * @var array<string,WorkId> Кандидаты с последней страницы курсора, по ID работы.
     */
    private array $expiredRunningRetries = [];

    /** @var array{sort: string, id: string}|null */
    private ?array $admittedCursor = null;

    /** @var array{sort: string, id: string}|null */
    private ?array $releasableBlockedCursor = null;

    /** @var array{sort: string, id: string}|null */
    private ?array $blockedProviderCursor = null;

    /** @var array{sort: string, id: string}|null */
    private ?array $cancelledProviderCursor = null;

    /** @var array{sort: string, id: string}|null */
    private ?array $uncheckedFaultsCursor = null;

    public function __construct(
        private readonly RecoveryRepository $recovery,
        private readonly AuthorityRepository $authority,
        private readonly IncidentRepository $incidents,
        private readonly ProcessTransaction $transaction,
        private readonly InvariantScan $invariantScan,
        ProviderRecoveryResolver $provider,
    ) {
        $this->provider = $provider;
    }

    public function bindProviderResolver(ProviderRecoveryResolver $resolver): void
    {
        $this->provider = $resolver;
    }

    public function providerResolver(): ProviderRecoveryResolver
    {
        return $this->provider;
    }

    /**
     * Категории ReconcileDaemon: сбой одной не останавливает остальные, blocked-provider
     * независима от expired-running очереди и не требует queue/receipt notification.
     *
     * @return list<ReconcileCategory>
     */
    public function categories(): array
    {
        return [
            $this->category('expired_running', $this->recoverExpiredRunning(...)),
            $this->category('admitted', $this->recoverAdmitted(...)),
            $this->category('blocked_release', $this->releaseBlocked(...)),
            $this->category('blocked_provider', $this->recoverBlockedProvider(...)),
            $this->category('handler_fault_sweep', $this->sweepHandlerFaults(...)),
            $this->invariantScan,
        ];
    }

    /** @param \Closure(): int $step */
    private function category(string $name, \Closure $step): RecoveryCategory
    {
        return new RecoveryCategory($name, function () use ($step): int {
            $this->workFailure = null;
            try {
                $processed = $step();
                if ($this->workFailure !== null) {
                    throw $this->workFailure;
                }

                return $processed;
            } finally {
                $this->workFailure = null;
            }
        });
    }

    /** @return int число recovered work */
    public function recoverExpiredRunning(): int
    {
        $recovered = 0;
        $retries = $this->expiredRunningRetries;
        foreach ($retries as $workId) {
            $recovered += $this->recoverOneExpiredRunning($workId);
        }
        // Повтор уже использован. Следующий tick запоминает сбои только с новой страницы.
        $this->expiredRunningRetries = [];
        foreach ($this->recovery->expiredRunning(self::BATCH, $this->expiredRunningCursor) as $workId) {
            if (isset($retries[$workId->value->value])) {
                continue;
            }
            $recovered += $this->recoverOneExpiredRunning($workId);
        }

        return $recovered;
    }

    private function recoverOneExpiredRunning(WorkId $workId): int
    {
        return $this->isolated('recover_running', $workId, function () use ($workId): int {
            try {
                $pinned = $this->recovery->pinnedPlan($workId);
                $finding = $this->provider->inspect($workId, $pinned['plan'] ?? null);
            } catch (ProviderLinkageUnavailable) {
                $this->openProviderLinkageIncident($workId);

                return 0;
            } catch (DependencyUnavailable $unavailable) {
                // Storage недоступен: dependency wait, новый provider send не разрешается — work остаётся running-expired.
                ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);
                $this->rememberExpiredRunning($workId);

                return 0;
            }
            $seq = $pinned['seq'] ?? 0;

            return $this->each($workId, true, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverRunning($snapshot, $finding, $seq, $this->provider, $ids));
        });
    }

    private function rememberExpiredRunning(WorkId $workId): void
    {
        $this->expiredRunningRetries[$workId->value->value] = $workId;
    }

    /**
     * Один poison work не останавливает категорию: его исключение видимо в логе, остальные кандидаты
     * этого tick обрабатываются. Каждый work — своя транзакция, поэтому откат затрагивает только его.
     * Daemon callback сообщает первую ошибку после batch, сохраняя degraded readiness.
     *
     * @param  \Closure(): int  $step
     */
    private function isolated(string $category, WorkId $workId, \Closure $step): int
    {
        try {
            return $step();
        } catch (\Throwable $failure) {
            $this->workFailure ??= $failure;
            ProcessLog::daemonError('reconcile', $category.'_work_failed', $failure, ['work_id' => $workId->value->value]);
            // workFailure хранит только первый сбой категории, но курсор уже прошёл каждый id.
            if ($category === 'recover_running' && ! $failure instanceof ProviderLinkageUnavailable) {
                $this->rememberExpiredRunning($workId);
            }

            return 0;
        }
    }

    public function recoverAdmitted(): int
    {
        $recovered = 0;
        foreach ($this->recovery->admittedCandidates(self::BATCH, $this->admittedCursor) as $workId) {
            $recovered += $this->isolated('recover_admitted', $workId, fn (): int => $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverAdmitted($snapshot, $ids)));
        }

        return $recovered;
    }

    public function releaseBlocked(): int
    {
        $recovered = 0;
        foreach ($this->recovery->releasableBlocked(self::BATCH, $this->releasableBlockedCursor) as $workId) {
            $recovered += $this->isolated('release_blocked', $workId, fn (): int => $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->releaseBlocked($snapshot, $ids)));
        }

        return $recovered;
    }

    /** selector provider_recovery_due_at, receipt verify вне locks, затем guarded transition. */
    public function recoverBlockedProvider(): int
    {
        $recovered = 0;
        $cancelled = $this->recovery->cancelledProvider(self::CANCELLED_PROVIDER_QUOTA, $this->cancelledProviderCursor);
        $blocked = $this->recovery->blockedProvider(self::BATCH - count($cancelled), $this->blockedProviderCursor);
        foreach ([...$blocked, ...$cancelled] as $workId) {
            $recovered += $this->isolated('recover_blocked_provider', $workId, function () use ($workId): int {
                $pinned = $this->recovery->pinnedPlan($workId);
                $plan = $pinned['plan'] ?? null;
                try {
                    // Missing exact linkage must reach the invariant path without a latest-call lookup.
                    $finding = $plan === null ? null : $this->provider->inspect($workId, $plan);
                } catch (ProviderLinkageUnavailable) {
                    $this->openProviderLinkageIncident($workId);
                    $finding = null;
                } catch (DependencyUnavailable $unavailable) {
                    // Storage/lookup недоступен: unknown и reserve сохранены, только отложить следующий scan.
                    ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);
                    // Reschedule through the same full prefix and checkpoint/state guards below.
                    $finding = null;
                }
                $seq = $pinned['seq'] ?? 0;

                return $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverProvider($snapshot, $finding, $seq, $this->provider, $ids));
            });
        }

        return $recovered;
    }

    /** Historical detector: durable unchecked fault attempts, окно от finished_at кандидата. */
    public function sweepHandlerFaults(): int
    {
        $checked = 0;
        $firstFailure = null;
        foreach ($this->recovery->uncheckedFaults(self::BATCH, $this->uncheckedFaultsCursor) as $candidate) {
            $ids = self::ids();
            try {
                if ($this->transaction->run(fn (): bool => $this->recovery->detectFault($candidate, $ids))) {
                    $checked++;
                }
            } catch (\Throwable $failure) {
                $firstFailure ??= $failure;
                $this->workFailure ??= $failure;
                ProcessLog::daemonError('reconcile', 'handler_fault_sweep_work_failed', $failure, [
                    'work_id' => $candidate['work_id'],
                    'attempt_id' => $candidate['attempt_id'],
                ]);
            }
        }
        if ($firstFailure !== null) {
            throw $firstFailure;
        }

        return $checked;
    }

    /**
     * Один work: unlocked snapshot → ProcessTransaction → при Reload новый snapshot. Corruption
     * открывает incident отдельной tx и не чинится молча.
     *
     * @param  \Closure(AuthoritySnapshot, array<string, ProcessUuid>): RecoveryOutcome  $locked
     * @return int 1, если состояние work изменено
     */
    private function each(WorkId $workId, bool $executing, \Closure $locked): int
    {
        $ids = self::ids();
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readRecoverySnapshot($workId, $executing);
            if ($snapshot instanceof AuthorityGap) {
                if ($snapshot !== AuthorityGap::WorkMissing) {
                    // Нет handler registration/authority/scopes в этом процессе: это конфигурационный дефект.
                    ProcessLog::daemonError('reconcile', 'recovery_authority_gap', null, ['work_id' => $workId->value->value, 'gap' => $snapshot->value]);
                }

                return 0;
            }
            $outcome = $this->transaction->run(fn (): RecoveryOutcome => $locked($snapshot, $ids));
            if ($outcome === RecoveryOutcome::Reload) {
                continue;
            }
            if ($outcome === RecoveryOutcome::Corrupt) {
                $this->transaction->run(fn () => $this->incidents->openMembershipIncident(
                    $snapshot->workId, 'work:'.$snapshot->workId->value->value, 'recovery',
                    $ids['incident'], $ids['incident_event'], $ids['incident_projection'],
                ));

                return 0;
            }

            return $outcome === RecoveryOutcome::Recovered ? 1 : 0;
        }

        return 0;
    }

    private function openProviderLinkageIncident(WorkId $work): void
    {
        $ids = self::ids();
        $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
            fingerprint: ContentHash::of('provider-linkage:v1:'.$work->value->value),
            scopeKey: 'work:'.$work->value->value,
            kind: 'provider_unknown_linkage_missing',
            evidence: ['work_id' => $work->value->value],
            incidentId: $ids['incident'], eventId: $ids['incident_event'], projectionMessageId: $ids['incident_projection'],
        ));
    }

    /**
     * Stable IDs до transaction retry: повтор tx не создаёт дубль transition/outbox.
     *
     * @return array<string, ProcessUuid>
     */
    private static function ids(): array
    {
        $ids = [];
        foreach (['event', 'projection', 'feed', 'wake', 'incident', 'incident_event', 'incident_projection'] as $name) {
            $ids[$name] = ProcessUuid::random();
        }

        return $ids;
    }
}
