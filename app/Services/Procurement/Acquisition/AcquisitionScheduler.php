<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\RunKind;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Repositories\Procurement\Crawler\CrawlStoreRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

/** Durable producers never execute a stage or publish a queue message directly. */
final readonly class AcquisitionScheduler
{
    public function __construct(private ProcessTransaction $transactions, private RunStoreRepository $runs,
        private CrawlStoreRepository $crawl, private ListingStoreRepository $listing,
        private WorkStoreRepository $works, private TransitionRepository $transitions) {}

    public function configure(int $profileId, AcquisitionKind $kind, string $cron, string $timezone,
        int $profileVersion): void
    {
        if ($profileId < 1 || $profileVersion < 1 || ! CronExpression::isValidExpression($cron)
            || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('Invalid acquisition schedule.');
        }
        $this->transactions->run(function () use ($profileId, $kind, $cron, $timezone, $profileVersion): void {
            $now = LedgerRow::one('SELECT clock_timestamp()::text AS now') ?? throw new \LogicException('Missing DB clock.');
            $next = (new CronExpression($cron))->getNextRunDate($now->string('now'), 0, true, $timezone);
            DB::insert('INSERT INTO prc_acquisition_schedules (profile_id,acquisition_kind,cron,timezone,profile_version,next_due_at)
                VALUES (?,?,?,?,?,?) ON CONFLICT (profile_id,acquisition_kind) DO UPDATE SET
                cron=EXCLUDED.cron, timezone=EXCLUDED.timezone, profile_version=EXCLUDED.profile_version,
                next_due_at=EXCLUDED.next_due_at WHERE prc_acquisition_schedules.profile_version<EXCLUDED.profile_version',
                [$profileId, $kind->value, $cron, $timezone, $profileVersion, $next->format('Y-m-d H:i:sP')]);
            $stored = LedgerRow::one('SELECT cron,timezone,profile_version FROM prc_acquisition_schedules
                WHERE profile_id=? AND acquisition_kind=? FOR UPDATE', [$profileId, $kind->value]);
            if ($stored?->int('profile_version') === $profileVersion
                && ($stored->string('cron') !== $cron || $stored->string('timezone') !== $timezone)) {
                throw new \InvalidArgumentException('Conflicting schedule for the same profile version.');
            }
        });
    }

    /** Prepared outside locks; the locked schedule rejects stale profile preparation. */
    public function due(AcquisitionLaunch $launch): ?ProcessUuid
    {
        return $this->transactions->run(function () use ($launch): ?ProcessUuid {
            $schedule = LedgerRow::one('SELECT cron,timezone,profile_version,next_due_at::text AS slot,
                clock_timestamp()::text AS now,next_due_at<=clock_timestamp() AS due
                FROM prc_acquisition_schedules WHERE profile_id=? AND acquisition_kind=? FOR UPDATE',
                [$launch->profileId, $launch->kind->value]);
            if ($schedule === null || ! $schedule->bool('due') || $schedule->int('profile_version') !== $launch->profileVersion) {
                return null;
            }
            $slot = new \DateTimeImmutable($schedule->string('slot'));
            $id = $this->allocate($launch, RunKind::Schedule, self::scheduledKey($launch->profileId, $launch->kind, $slot), null);
            // Coalesce missed cron slots. Immutable coverage debt, not queued missed minutes,
            // owns the unobserved source range.
            $next = (new CronExpression($schedule->string('cron')))->getNextRunDate(
                $schedule->string('now'), 0, false, $schedule->string('timezone'));
            DB::update('UPDATE prc_acquisition_schedules SET next_due_at=? WHERE profile_id=? AND acquisition_kind=?',
                [$next->format('Y-m-d H:i:sP'), $launch->profileId, $launch->kind->value]);

            return $id;
        });
    }

    public function scheduled(AcquisitionLaunch $launch, \DateTimeImmutable $slot): ProcessUuid
    {
        return $this->transactions->run(fn (): ProcessUuid => $this->allocate($launch, RunKind::Schedule,
            self::scheduledKey($launch->profileId, $launch->kind, $slot), null));
    }

    public function manual(AcquisitionLaunch $launch, ProcessUuid $command, ?int $actor): ProcessUuid
    {
        return $this->transactions->run(fn (): ProcessUuid => $this->allocate($launch, RunKind::Manual, 'manual:'.$command->value, $actor));
    }

    public function recovery(AcquisitionLaunch $launch, ProcessUuid $root, string $day): ProcessUuid
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new \InvalidArgumentException('Invalid recovery cycle.');
        }

        return $this->transactions->run(fn (): ProcessUuid => $this->allocate($launch, RunKind::Recovery,
            'reconcile:'.$root->value.':'.$day, null));
    }

    public static function scheduledKey(int $profileId, AcquisitionKind $kind, \DateTimeImmutable $slot): string
    {
        return 'schedule:'.$profileId.':'.$kind->value.':'.$slot->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function allocate(AcquisitionLaunch $launch, RunKind $kind, string $trigger, ?int $actor): ProcessUuid
    {
        // Stable IDs survive transaction retries. No existing work is locked by this producer.
        $id = ListingStoreRepository::id('producer:'.$trigger);
        $crawlId = ListingStoreRepository::id('crawl:'.$trigger);
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition:'.$trigger]);
        $existing = LedgerRow::one("SELECT r.id,r.profile_id,r.requested_by,r.configuration_snapshot::text AS configuration,r.configuration_schema_version,
            c.listing_kind,encode(c.filter_hash,'hex') AS filter_hash,encode(c.access_policy_hash,'hex') AS access_hash,c.adapter_version FROM prc_process_runs r
            JOIN prc_crawl_runs c ON c.process_run_id=r.id WHERE r.trigger_key=?", [$trigger]);
        if ($existing !== null) {
            if ($existing->int('profile_id') !== $launch->profileId
                || $existing->string('listing_kind') !== $launch->kind->value
                || ($kind === RunKind::Manual && ($existing->nullableInt('requested_by') !== $actor
                    || self::requestFingerprint(new VersionedPayload($existing->string('configuration'), $existing->int('configuration_schema_version')))->hex !== self::requestFingerprint($launch->configuration)->hex
                    || $existing->string('filter_hash') !== $launch->filter->payloadHash->hex
                    || $existing->string('access_hash') !== $launch->accessPolicyHash->hex
                    || $existing->string('adapter_version') !== $launch->adapterVersion))) {
                throw new \InvalidArgumentException('Command UUID belongs to another immutable acquisition request.');
            }

            return new ProcessUuid($existing->string('id'));
        }
        $profile = LedgerRow::one('SELECT c.registry_id,c.profile_version,c.is_active,r.platform_id,r.code FROM prc_crawlers c
            JOIN prc_registries r ON r.id=c.registry_id WHERE c.id=? FOR SHARE OF c', [$launch->profileId]);
        if ($profile === null || $profile->int('registry_id') !== $launch->registryId
            || $profile->int('platform_id') !== $launch->platformId || $profile->string('code') !== $launch->registry) {
            throw new \InvalidArgumentException('Acquisition profile/registry/platform mismatch.');
        }
        if (! $profile->bool('is_active') || $profile->int('profile_version') !== $launch->profileVersion) {
            throw new \InvalidArgumentException('Acquisition profile changed during preparation.');
        }
        $this->runs->storeRun($id, $kind, $trigger, $launch->profileId, $launch->configuration, $actor,
            ListingStoreRepository::id('run-event:'.$trigger), ListingStoreRepository::id('run-project:'.$trigger));
        $this->crawl->storeCrawlRun($crawlId, $id, $launch->kind,
            new VersionedPayload($launch->filter->serializedPayload, $launch->filter->schemaVersion), $launch->accessPolicyHash, $launch->adapterVersion);
        $inputs = $this->listing->allocate($crawlId, $launch->plan, $launch->registryId, $launch->registry, $launch->filter, $launch->streamKey);
        foreach ($inputs as $input) {
            $key = $input->crawlBlockId->value;
            $work = new WorkId(ListingStoreRepository::id('root-work:'.$key));
            $this->works->storeWork($work, $input, 1, null, 'platform:'.$launch->platformId, $launch->platformId, $id, 100,
                ListingStoreRepository::id('root-event:'.$key), ListingStoreRepository::id('root-project:'.$key));
            $this->works->linkRun($id, $work, RunWorkRelation::Created, AccountingRole::Required);
        }
        if ($inputs === []) {
            $outcome = $launch->plan->continuation === null ? 'complete' : 'incomplete';
            $reason = $outcome === 'complete' ? null : 'root_allocation_bound';
            DB::update('UPDATE prc_process_runs SET discovery_outcome=?,discovery_reason_code=?,
                discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?', [$outcome, $reason, $id->value]);
            $this->transitions->recordRun(ListingStoreRepository::id('close-event:'.$trigger), ListingStoreRepository::id('close-project:'.$trigger),
                $id, 2, 'collecting', $outcome, $reason, ActorKind::System);
        }

        return $id;
    }

    private static function requestFingerprint(VersionedPayload $configuration): ContentHash
    {
        if ($configuration->schemaVersion === 2) {
            return AcquisitionConfiguration::decode($configuration)->requestFingerprint();
        }
        $fields = json_decode($configuration->json ?? throw new \InvalidArgumentException('Missing acquisition configuration.'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($fields) || array_is_list($fields)) {
            throw new \InvalidArgumentException('Acquisition configuration must be an object.');
        }
        ksort($fields, SORT_STRING);

        return ContentHash::of(json_encode([$configuration->schemaVersion, $fields], JSON_THROW_ON_ERROR));
    }
}
