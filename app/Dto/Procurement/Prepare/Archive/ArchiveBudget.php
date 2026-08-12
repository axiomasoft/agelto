<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Archive;

final readonly class ArchiveBudget
{
    public function __construct(
        public int $maxDepth,
        public int $maxEntries,
        public int $maxEntryBytes,
        public int $maxTotalBytes,
        public int $maxCompressionRatio,
        public int $maxPathLength,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxDepth: (int) config('ai.prepare.archive.max_depth', 2),
            maxEntries: (int) config('ai.prepare.archive.max_files', 200),
            maxEntryBytes: (int) config('ai.prepare.archive.max_entry_mb', 100) * 1024 * 1024,
            maxTotalBytes: (int) config('ai.prepare.archive.max_total_mb', 500) * 1024 * 1024,
            maxCompressionRatio: (int) config('ai.prepare.archive.max_ratio', 100),
            maxPathLength: (int) config('ai.prepare.archive.max_path_length', 240),
        );
    }
}
