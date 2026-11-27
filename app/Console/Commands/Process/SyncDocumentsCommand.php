<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Document\DownloadPolicy;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('process:sync-documents')]
final class SyncDocumentsCommand extends Command
{
    public function handle(ProcessAuthoritySync $authority, StorageCapacityRepository $capacity, DownloadPolicy $download, PreparePolicy $prepare): int
    {
        $domains = config('documents.storage');
        foreach (array_unique([$download->durableDomain, $download->temporaryDomain, $prepare->durableDomain, $prepare->temporaryDomain]) as $key) {
            $limits = is_array($domains) ? ($domains[$key] ?? null) : null;
            if (! is_array($limits) || array_keys($limits) !== ['capacity_bytes', 'high_watermark_bytes']
                || ! is_int($limits['capacity_bytes']) || ! is_int($limits['high_watermark_bytes'])) {
                throw new \InvalidArgumentException('documents.storage.'.$key.': explicit integer capacities required.');
            }
            $capacity->ensureDomain(ProcessUuid::derived('storage-domain:'.$key), $key, $limits['capacity_bytes'], $limits['high_watermark_bytes']);
        }
        $authority->ensureBaseline();
        foreach ([StageCode::Download, StageCode::Prepare] as $stage) {
            $authority->registerHandler($stage, DocumentRuntime::VERSION);
        }
        $this->info('Document authorities and storage catalog synchronized.');

        return self::SUCCESS;
    }
}
