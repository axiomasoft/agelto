<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Storage\MinioLifecycleManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Идемпотентно применяет MinIO ILM-правила из `config('partitioning.minio_lifecycle')`  на бакет S3-диска — логика ушла в `MinioLifecycleManager`.
 */
#[Description('Идемпотентно применяет MinIO ILM-правила (config(partitioning.minio_lifecycle)) на бакет s3-диска')]
#[AsCommand(name: 'ai:storage:lifecycle')]
#[Signature('ai:storage:lifecycle')]
final class StorageLifecycleCommand extends Command
{
    public function __construct(private readonly MinioLifecycleManager $manager)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->manager->apply() as $entry) {
            $this->info($entry['applied']
                ? "Правило «{$entry['key']}» добавлено: {$entry['prefix']} → expire {$entry['expire_days']}д."
                : "Правило «{$entry['key']}» (префикс {$entry['prefix']}) уже существует — пропуск (идемпотентность).");
        }

        return self::SUCCESS;
    }
}
