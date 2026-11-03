<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Services\Procurement\Process\RawArtifactRetention;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('process:retention {--limit=500 : Maximum retired raw objects per batch}')]
final class ProcessRetentionCommand extends Command
{
    public function handle(RawArtifactRetention $raw): int
    {
        $seconds = config('documents.temporary_stale_seconds');
        if (! is_int($seconds) || $seconds < 1) {
            throw new \UnexpectedValueException('documents.temporary_stale_seconds must be a positive integer.');
        }
        $result = $this->call('process:prune-history');
        if ($result !== self::SUCCESS) {
            return $result;
        }
        $removed = $raw->prune(max(1, min(5000, (int) $this->option('limit'))));
        $temporary = PipelineTemporaryFile::purgeOlderThan($seconds);
        $this->info("Retired raw objects: {$removed}; temporary objects: {$temporary}.");

        return self::SUCCESS;
    }
}
