<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Writer реальных listing subjects (prc_crawl_runs/prc_crawl_blocks). Snapshot immutable, без
 * execution state; cursor/terminal proof меняет только guarded work checkpoint/finalize.
 */
final readonly class CrawlStoreRepository
{
    public function storeCrawlRun(
        ProcessUuid $id,
        ProcessUuid $processRunId,
        AcquisitionKind $kind,
        VersionedPayload $filter,
        ContentHash $accessPolicyHash,
        string $adapterVersion,
    ): ContentHash {
        CallerTransaction::require('Crawl run insert');
        if ($filter->json === null) {
            throw new \InvalidArgumentException('Crawl filter snapshot is required.');
        }
        $filterHash = ContentHash::of($filter->json);
        DB::insert(
            "INSERT INTO prc_crawl_runs
               (id, process_run_id, listing_kind, filter_payload, filter_schema_version, filter_hash, access_policy_hash, adapter_version)
             VALUES (?, ?, ?, ?::jsonb, ?, decode(?, 'hex'), decode(?, 'hex'), ?)",
            [$id->value, $processRunId->value, $kind->value, $filter->json, $filter->schemaVersion, $filterHash->hex, $accessPolicyHash->hex, $adapterVersion],
        );

        return $filterHash;
    }

    /** Block наследует filter_hash своего crawl run; отсутствующий run — ошибка, не placeholder. */
    public function storeCrawlBlock(
        ProcessUuid $id,
        ProcessUuid $crawlRunId,
        ?ProcessUuid $parentBlockId,
        string $blockKey,
        VersionedPayload $partition,
    ): void {
        CallerTransaction::require('Crawl block insert');
        if ($partition->json === null) {
            throw new \InvalidArgumentException('Crawl block partition is required.');
        }
        $inserted = DB::affectingStatement(
            'INSERT INTO prc_crawl_blocks (id, crawl_run_id, parent_block_id, block_key, partition_payload, partition_schema_version, filter_hash)
             SELECT ?, r.id, ?, ?, ?::jsonb, ?, r.filter_hash FROM prc_crawl_runs r WHERE r.id = ?',
            [$id->value, $parentBlockId?->value, $blockKey, $partition->json, $partition->schemaVersion, $crawlRunId->value],
        );
        if ($inserted !== 1) {
            throw new \InvalidArgumentException('Crawl block requires an existing crawl run.');
        }
    }
}
