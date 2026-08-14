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
            maxDepth: self::nonNegativeInt('ai.prepare.archive.max_depth', 2),
            maxEntries: self::nonNegativeInt('ai.prepare.archive.max_files', 200),
            maxEntryBytes: self::positiveInt('ai.prepare.archive.max_entry_mb', 100) * 1024 * 1024,
            maxTotalBytes: self::positiveInt('ai.prepare.archive.max_total_mb', 500) * 1024 * 1024,
            maxCompressionRatio: self::positiveInt('ai.prepare.archive.max_ratio', 100),
            maxPathLength: self::positiveInt('ai.prepare.archive.max_path_length', 240),
        );
    }

    private static function positiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }

    private static function nonNegativeInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("{$key} must be a non-negative integer.");
        }

        return $value;
    }
}
