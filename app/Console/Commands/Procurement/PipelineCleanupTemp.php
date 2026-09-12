<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Удаляет осиротевшие файлы и workspaces document pipeline старше безопасного lease-окна')]
#[Signature('pipeline:cleanup-temp')]
final class PipelineCleanupTemp extends Command
{
    public function handle(): int
    {
        $seconds = config('ai.pipeline.temporary_stale_seconds', 86400);

        if (! is_int($seconds) || $seconds < 1) {
            throw new \UnexpectedValueException('ai.pipeline.temporary_stale_seconds must be a positive integer.');
        }

        $removed = PipelineTemporaryFile::purgeOlderThan(seconds: $seconds);
        $this->info("Удалено временных объектов: {$removed}");

        return self::SUCCESS;
    }
}
