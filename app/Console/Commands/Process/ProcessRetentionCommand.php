<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Repositories\Procurement\Process\Work\OutboxRepository;
use App\Services\Procurement\Process\Diagnostics\RawArtifactRetention;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('process:retention {--limit=500 : Maximum rows per batch for retired raw objects and published outbox messages}')]
final class ProcessRetentionCommand extends Command
{
    private const int OUTBOX_RETENTION_DAYS = 30;

    public function handle(RawArtifactRetention $raw, OutboxRepository $outbox): int
    {
        $seconds = config('documents.temporary_stale_seconds');
        if (! is_int($seconds) || $seconds < 1) {
            throw new \UnexpectedValueException('documents.temporary_stale_seconds must be a positive integer.');
        }
        $result = $this->call('process:prune-history');
        if ($result !== self::SUCCESS) {
            return $result;
        }
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $published = $outbox->prunePublished(retentionDays: self::OUTBOX_RETENTION_DAYS, limit: $limit);
        $removed = $raw->prune($limit);
        $temporary = PipelineTemporaryFile::purgeOlderThan($seconds);
        $this->info("Retired raw objects: {$removed}; temporary objects: {$temporary}.");
        $this->info("Published outbox messages removed: {$published}.");

        return self::SUCCESS;
    }
}
