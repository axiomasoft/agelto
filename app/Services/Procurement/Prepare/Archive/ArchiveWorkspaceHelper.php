<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final readonly class ArchiveWorkspaceHelper
{
    public static function copyToLocal(
        Filesystem $disk,
        string $storagePath,
        string $localPath,
        ?ArchiveBudget $budget = null,
        ?float $deadlineAt = null,
    ): void {
        if ($budget === null) {
            $source = $disk->readStream($storagePath);

            if (! is_resource($source)) {
                throw new RuntimeException("Оригинал недоступен на диске: {$storagePath}");
            }

            $target = null;

            try {
                $target = fopen($localPath, 'wb');

                if (! is_resource($target)) {
                    throw new RuntimeException("Не удалось открыть временный файл: {$localPath}");
                }

                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException("Не удалось скопировать поток во временный файл: {$localPath}");
                }
            } finally {
                if (is_resource($target)) {
                    fclose($target);
                }

                fclose($source);
            }

            return;
        }

        self::assertWithinDeadline($deadlineAt);
        $stream = $disk->readStream($storagePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Оригинал архива недоступен на диске: {$storagePath}");
        }

        $target = fopen($localPath, 'wb');

        if (! is_resource($target)) {
            fclose($stream);
            throw new RuntimeException("Не удалось открыть временный файл архива: {$localPath}");
        }

        try {
            $written = 0;

            while (! feof($stream)) {
                self::assertWithinDeadline($deadlineAt);
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    throw new RuntimeException("Ошибка чтения потока архива: {$storagePath}");
                }

                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);

                if ($written > $budget->maxTotalBytes) {
                    throw new ArchiveValidationException('превышен суммарный размер архива');
                }

                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Не удалось записать временный файл архива: {$localPath}");
                }
            }
        } finally {
            fclose($target);
            fclose($stream);
        }
    }

    public static function boundedTimeout(int $configured, ?float $deadlineAt): int
    {
        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = (int) floor($deadlineAt - microtime(true) - self::persistenceReserveSeconds());

        if ($remaining < 1) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }

        return min($configured, $remaining);
    }

    public static function assertWithinDeadline(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt - self::persistenceReserveSeconds()) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }
    }

    /**
     * @return array{int, int, int}
     */
    public static function lineageUsage(DocumentVersion $version): array
    {
        $depth = 0;
        $rootVersionId = $version->id;
        $parentId = $version->parent_version_id;
        $visitedAncestors = [$version->id => true];

        while ($parentId !== null) {
            if (isset($visitedAncestors[$parentId])) {
                break;
            }

            $visitedAncestors[$parentId] = true;
            $depth++;
            $rootVersionId = $parentId;
            $parent = DocumentVersion::query()->find($parentId);
            $parentId = $parent?->parent_version_id;
        }

        if ($depth === 0) {
            return [0, 0, 0];
        }

        $count = 0;
        $bytes = 0;
        $frontier = [$rootVersionId];
        $visited = [$rootVersionId => true];

        while ($frontier !== []) {
            $children = DocumentVersion::query()
                ->whereIn('parent_version_id', $frontier)
                ->get(['id', 'size_bytes']);
            $frontier = [];

            foreach ($children as $child) {
                if (isset($visited[$child->id])) {
                    continue;
                }

                $visited[$child->id] = true;
                $count++;
                $bytes += (int) $child->size_bytes;

                if ($child->id !== $version->id) {
                    $frontier[] = $child->id;
                }
            }
        }

        return [$depth, $count, $bytes];
    }

    private static function persistenceReserveSeconds(): int
    {
        $reserve = config('documents.format_tools.persistence_seconds', 120);

        return is_int($reserve) && $reserve >= 0 ? $reserve : 120;
    }
}
