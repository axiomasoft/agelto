<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\RecoveryOutcome;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\RecoveryRepository;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

/**
 * Единственный recovery owner (bp-sql-recover, execution#s06): expired running и expired/paused
 * admitted, release blocked(paused|handler_hold), независимая категория EXR-03 blocked
 * provider_result_unknown и historical handler-fault sweep. Каждая категория — bounded batch (<=50),
 * для каждого work отдельная ProcessTransaction под полным prefix с повтором locked predicates;
 * изменившийся snapshot — rollback и новый pre-read (не более трёх раз), мёртвая delivery/Redis
 * здесь не участвуют. Pre-read provider facts (object I/O) выполняется вне DB locks, под locks
 * resolver только пишет settlement. Пока provider stages не активны, resolver — NoProviderOperations;
 * P3.8–P3.14 подключает настоящий через bindProviderResolver.
 */
#[Singleton]
final class RecoveryReconciler
{
    public const int BATCH = 50;

    /** Snapshot менялся на каждой попытке: work остаётся durable, следующий tick повторит. */
    private const int MAX_RELOADS = 3;

    private ProviderRecoveryResolver $provider;

    public function __construct(
        private readonly RecoveryRepository $recovery,
        private readonly AuthorityRepository $authority,
        private readonly IncidentRepository $incidents,
        private readonly ProcessTransaction $transaction,
        private readonly InvariantScan $invariantScan,
    ) {
        $this->provider = new NoProviderOperations;
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
            new RecoveryCategory('expired_running', $this->recoverExpiredRunning(...)),
            new RecoveryCategory('admitted', $this->recoverAdmitted(...)),
            new RecoveryCategory('blocked_release', $this->releaseBlocked(...)),
            new RecoveryCategory('blocked_provider', $this->recoverBlockedProvider(...)),
            new RecoveryCategory('handler_fault_sweep', $this->sweepHandlerFaults(...)),
            $this->invariantScan,
        ];
    }

    /** @return int число recovered work */
    public function recoverExpiredRunning(): int
    {
        $recovered = 0;
        foreach ($this->recovery->expiredRunning(self::BATCH) as $workId) {
            try {
                $pinned = $this->recovery->pinnedPlan($workId);
                $finding = $this->provider->inspect($workId, $pinned['plan'] ?? null);
            } catch (DependencyUnavailable $unavailable) {
                // Storage недоступен: dependency wait, новый provider send не разрешается — work остаётся running-expired.
                ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);

                continue;
            }
            $seq = $pinned['seq'] ?? 0;
            $recovered += $this->each($workId, true, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverRunning($snapshot, $finding, $seq, $this->provider, $ids));
        }

        return $recovered;
    }

    public function recoverAdmitted(): int
    {
        $recovered = 0;
        foreach ($this->recovery->admittedCandidates(self::BATCH) as $workId) {
            $recovered += $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverAdmitted($snapshot, $ids));
        }

        return $recovered;
    }

    public function releaseBlocked(): int
    {
        $recovered = 0;
        foreach ($this->recovery->releasableBlocked(self::BATCH) as $workId) {
            $recovered += $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->releaseBlocked($snapshot, $ids));
        }

        return $recovered;
    }

    /** EXR-03: selector provider_recovery_due_at, receipt verify вне locks, затем guarded transition. */
    public function recoverBlockedProvider(): int
    {
        $recovered = 0;
        foreach ($this->recovery->blockedProvider(self::BATCH) as $workId) {
            $pinned = $this->recovery->pinnedPlan($workId);
            $plan = $pinned['plan'] ?? null;
            try {
                // Missing exact linkage must reach the invariant path without a latest-call lookup.
                $finding = $plan === null ? null : $this->provider->inspect($workId, $plan);
            } catch (DependencyUnavailable $unavailable) {
                // Storage/lookup недоступен: unknown и reserve сохранены, только отложить следующий scan.
                ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);
                // Reschedule through the same full prefix and checkpoint/state guards below.
                $finding = null;
            }
            $seq = $pinned['seq'] ?? 0;
            $recovered += $this->each($workId, false, fn (AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome => $this->recovery->recoverProvider($snapshot, $finding, $seq, $this->provider, $ids));
        }

        return $recovered;
    }

    /** Historical detector: durable unchecked fault attempts, окно от finished_at кандидата. */
    public function sweepHandlerFaults(): int
    {
        $checked = 0;
        foreach ($this->recovery->uncheckedFaults(self::BATCH) as $candidate) {
            $ids = self::ids();
            if ($this->transaction->run(fn (): bool => $this->recovery->detectFault($candidate, $ids))) {
                $checked++;
            }
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
                $this->openMembershipIncident($snapshot, $ids);

                return 0;
            }

            return $outcome === RecoveryOutcome::Recovered ? 1 : 0;
        }

        return 0;
    }

    /** @param array<string, ProcessUuid> $ids */
    private function openMembershipIncident(AuthoritySnapshot $snapshot, array $ids): void
    {
        $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
            fingerprint: ContentHash::of('admission-membership:v1:'.$snapshot->workId->value->value),
            scopeKey: 'work:'.$snapshot->workId->value->value,
            kind: 'admission_membership_mismatch',
            evidence: ['work_id' => $snapshot->workId->value->value],
            incidentId: $ids['incident'],
            eventId: $ids['incident_event'],
            projectionMessageId: $ids['incident_projection'],
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
            $ids[$name] = new ProcessUuid(Str::lower((string) Str::uuid()));
        }

        return $ids;
    }
}
