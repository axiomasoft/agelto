<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;

/** Reconciliation retains the original filter and access policy after the fresh horizon moves on. */
final readonly class ListingRecovery
{
    public function __construct(private ListingCoverageRepository $coverage, private AcquisitionProfiles $profiles,
        private AcquisitionScheduler $scheduler) {}

    public function due(Crawler $profile): int
    {
        $clock = LedgerRow::one('SELECT clock_timestamp()::text AS now') ?? throw new \LogicException('Missing DB clock.');
        $now = new \DateTimeImmutable($clock->string('now'));
        $streams = LedgerRow::all("SELECT DISTINCT b.partition_payload->>'streamKey' AS stream
            FROM prc_crawl_blocks b JOIN prc_crawl_runs c ON c.id=b.crawl_run_id
            JOIN prc_process_runs r ON r.id=c.process_run_id WHERE r.profile_id=? ORDER BY stream", [$profile->id]);
        $count = 0;
        foreach ($streams as $stream) {
            foreach ($this->coverage->due(new ContentHash($stream->string('stream')), $now, 100 - $count) as $task) {
                $assignment = ListingStoreRepository::id('closing:'.$task->rootBlockId->value.':'.$task->windowStart.':'.$task->windowEnd);
                $day = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
                if (LedgerRow::one('SELECT id FROM prc_process_runs WHERE trigger_key=?', ['reconcile:'.$assignment->value.':'.$day]) !== null) {
                    continue;
                }
                $original = LedgerRow::one("SELECT c.listing_kind,c.adapter_version,encode(c.access_policy_hash,'hex') AS access_hash,
                    r.configuration_snapshot::text AS configuration,r.configuration_schema_version
                    FROM prc_crawl_blocks b JOIN prc_crawl_runs c ON c.id=b.crawl_run_id
                    JOIN prc_process_runs r ON r.id=c.process_run_id
                    WHERE (b.id=? OR b.partition_payload->>'streamKey'=?) AND r.profile_id=?
                    ORDER BY (b.id = ?::uuid) DESC, r.requested_at DESC LIMIT 1",
                    [$task->rootBlockId->value, $task->streamKey->hex, $profile->id, $task->rootBlockId->value])
                    ?? throw new \LogicException('Missing coverage provenance.');
                $registry = $profile->registry ?? throw new \LogicException('Missing recovery registry.');
                $launch = new AcquisitionLaunch($profile->id, $profile->profile_version, $registry->platform_id,
                    $task->registryId, $task->registry, AcquisitionKind::from($original->string('listing_kind')),
                    $original->string('adapter_version'), $task->filter, new ContentHash($original->string('access_hash')),
                    $task->streamKey, $this->profiles->preparer($profile)->recoveryPlan($task),
                    new VersionedPayload($original->string('configuration'), $original->int('configuration_schema_version')));
                $this->scheduler->recovery($launch, $assignment, $day);
                if (++$count === 100) {
                    return $count;
                }
            }
        }

        return $count;
    }
}
