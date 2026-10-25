<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\RunKind;
use App\Repositories\Procurement\Crawler\CrawlStoreRepository;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real P1.6 ledger rows through the owning writers; raw rows only for DB CHECK/FK negatives. */
final class LedgerFixtures
{
    public static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }

    public static function hash(string $seed): ContentHash
    {
        return ContentHash::of('ledger-fixture:'.$seed);
    }

    /**
     * Process run → real crawl run → real crawl block, inside the caller transaction.
     *
     * @return array{run: ProcessUuid, crawl_run: ProcessUuid, block: ProcessUuid, filter_hash: ContentHash, access_policy_hash: ContentHash}
     */
    public static function crawlBlock(?string $triggerKey = null): array
    {
        $run = self::uuid();
        $crawlRun = self::uuid();
        $block = self::uuid();
        $accessPolicyHash = self::hash('access');
        app(RunStoreRepository::class)->storeRun(
            id: $run,
            kind: RunKind::Manual,
            triggerKey: $triggerKey,
            profileId: null,
            configuration: new VersionedPayload('{"fixture":"p1.6"}', 1),
            requestedBy: null,
            eventId: self::uuid(),
            projectionMessageId: self::uuid(),
        );
        $filterHash = app(CrawlStoreRepository::class)->storeCrawlRun(
            id: $crawlRun,
            processRunId: $run,
            kind: AcquisitionKind::Fresh,
            filter: new VersionedPayload('{"region":"fixture"}', 1),
            accessPolicyHash: $accessPolicyHash,
            adapterVersion: 'fixture-listing/1',
        );
        app(CrawlStoreRepository::class)->storeCrawlBlock(
            id: $block,
            crawlRunId: $crawlRun,
            parentBlockId: null,
            blockKey: 'day:2026-10-25',
            partition: new VersionedPayload('{"from":"2026-10-25","to":"2026-10-25"}', 1),
        );

        return ['run' => $run, 'crawl_run' => $crawlRun, 'block' => $block, 'filter_hash' => $filterHash, 'access_policy_hash' => $accessPolicyHash];
    }

    public static function listingInput(ProcessUuid $block, ContentHash $filterHash, ContentHash $accessPolicyHash): ListingInput
    {
        return new ListingInput($block, $filterHash, $accessPolicyHash, 'fixture-listing/1', 1);
    }

    public static function detailInput(int $procurementId, ProcessUuid $intentId): DetailInput
    {
        return new DetailInput($procurementId, $intentId, 1, self::hash('semantic'), self::hash('access'), 'fixture-detail/1');
    }

    public static function statusInput(int $procurementId, int $cycleSeq = 1): StatusRefreshInput
    {
        return new StatusRefreshInput($procurementId, $cycleSeq, self::hash('status-policy'), 'fixture-status/1');
    }

    public static function downloadInput(int $documentId, ProcessUuid $intentId): DownloadInput
    {
        return new DownloadInput($documentId, $intentId, 1, self::hash('access'));
    }

    public static function storeWork(WorkInput $input, ?ProcessUuid $intentId = null, ?ProcessUuid $originRunId = null, ?WorkId $id = null): WorkId
    {
        $id ??= new WorkId(self::uuid());
        app(WorkStoreRepository::class)->storeWork(
            id: $id,
            input: $input,
            inputRevision: 1,
            intentId: $intentId,
            cohortKey: 'manual',
            platformId: null,
            originRunId: $originRunId,
            priority: 100,
            eventId: self::uuid(),
            projectionMessageId: self::uuid(),
        );

        return $id;
    }

    /**
     * Minimal schema-valid listing work row on a real block, then raw overrides. DB negatives only:
     * the owning writer never produces these combinations.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertRawWork(ProcessUuid $block, array $overrides = []): ProcessUuid
    {
        $id = self::uuid();
        DB::table('prc_process_work')->insert([
            'id' => $id->value,
            'stage' => 'listing',
            'work_key' => DB::raw("decode('".self::hash('raw-key:'.$id->value)->hex."', 'hex')"),
            'work_identity' => '{}',
            'work_identity_schema_version' => 1,
            'input_revision' => 1,
            'input_hash' => DB::raw("decode('".self::hash('raw-input')->hex."', 'hex')"),
            'input_payload' => '{}',
            'input_schema_version' => 1,
            'state' => 'ready',
            'cohort_key' => 'manual',
            'crawl_block_id' => $block->value,
            'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
            ...$overrides,
        ]);

        return $id;
    }

    /** SQLSTATE of the statement inside its own savepoint, or null when the database accepted it. */
    public static function sqlState(Closure $statement): ?string
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return (string) ($exception->errorInfo[0] ?? $exception->getCode());
        }

        return null;
    }
}
