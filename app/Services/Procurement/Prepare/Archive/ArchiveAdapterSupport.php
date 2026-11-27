<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Services\Procurement\Prepare\Tools\BoundedToolProcess;
use App\Support\Config\TypedConfig;
use App\Support\Streams\BoundedStreamException;
use App\Support\Streams\BoundedStreamHasher;
use Closure;
use RuntimeException;
use Throwable;

/** Shared stream, encoding, deadline, and external tool helpers for archive adapters. */
final class ArchiveAdapterSupport
{
    public static function decodeEntryName(string $rawName): string
    {
        if (mb_check_encoding($rawName, 'UTF-8')) {
            return $rawName;
        }

        $isWindows1251 = preg_match('/[\xB0-\xDF\xF2-\xFB\xFD-\xFF]/', $rawName) === 1
            && preg_match('/[\x80-\xA7\xA9-\xAF]/', $rawName) !== 1;
        $encodings = $isWindows1251 ? ['Windows-1251', 'CP866'] : ['CP866', 'Windows-1251'];

        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8', $rawName);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        throw new ArchiveUnsupportedException('не удалось декодировать имя записи архива');
    }

    /** @param resource $input */
    public static function copyBounded(
        mixed $input,
        string $targetPath,
        ArchiveEntry $entry,
        ArchiveBudget $budget,
        int &$totalBytes,
        ?float $deadlineAt,
    ): void {
        $output = fopen($targetPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("не удалось создать временный файл entry: {$targetPath}");
        }

        $maxBytes = min($entry->declaredSize, $budget->maxEntryBytes, max(0, $budget->maxTotalBytes - $totalBytes));

        try {
            $result = BoundedStreamHasher::process(
                source: $input,
                maxBytes: $maxBytes,
                sink: $output,
                chunkSize: 65536,
                hash: false,
                onTick: static fn () => self::assertWithinDeadline($deadlineAt),
            );
            $bytes = $result->bytes;
            if ($bytes !== $entry->declaredSize) {
                throw new ArchiveValidationException('фактический размер записи не совпал с metadata');
            }
        } catch (BoundedStreamException $exception) {
            fclose($output);
            unlink($targetPath);

            throw match ($exception->reason) {
                BoundedStreamException::REASON_OVERFLOW => new ArchiveValidationException('фактические байты записи архива превысили бюджет', previous: $exception),
                BoundedStreamException::REASON_READ => new ArchiveUnsupportedException('ошибка чтения записи архива', previous: $exception),
                default => new RuntimeException('не удалось записать временный файл entry', previous: $exception),
            };
        } catch (Throwable $exception) {
            fclose($output);
            unlink($targetPath);

            throw $exception;
        }

        fclose($output);
        $totalBytes += $bytes;
    }

    public static function assertWithinDeadline(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt - self::persistenceReserveSeconds()) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }
    }

    /**
     * @param  non-empty-list<string>  $command
     * @param  (Closure(resource): void)|null  $consumeOutput
     */
    public static function runTool(
        BoundedToolProcess $tools,
        array $command,
        string $archivePath,
        ?float $deadlineAt,
        ?string $member = null,
        ?Closure $consumeOutput = null,
        int $maxOutputBytes = 8388608,
    ): string {
        $deadline = hrtime(true) / 1e9 + self::timeout($deadlineAt);

        try {
            $source = $tools->inputPath($archivePath);
            $command[] = $source;
            if ($member !== null) {
                $command[] = $member;
            }

            return $tools->inWorkspace(
                prefix: 'archive_tool_',
                operation: static fn (string $directory): string => $tools->run(
                    command: $command,
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                    tmpBytes: $maxOutputBytes + 1048576,
                    inputPath: $source,
                    consumeOutput: $consumeOutput,
                    maxOutputBytes: $maxOutputBytes,
                    reportStderr: true,
                ),
            );
        } catch (ToolFailed|ToolUnavailable $exception) {
            if ($exception instanceof ToolFailed) {
                if ($exception->reason === 'page_timeout') {
                    throw new RuntimeException('Истекло время работы распаковщика.', previous: $exception);
                }
                if (in_array($exception->reason, ['tool_output_limit', 'tmp_limit'], true)) {
                    throw new ArchiveValidationException('вывод распаковщика превысил бюджет', previous: $exception);
                }
            }

            self::rejectByOutput($exception->getMessage());
        }
    }

    public static function rejectByOutput(string $output): never
    {
        $output = trim($output);
        $lower = mb_strtolower($output, 'UTF-8');
        if (
            str_contains($lower, 'password')
            || str_contains($lower, 'can not open encrypted archive')
            || str_contains($lower, 'cannot open encrypted archive')
            || preg_match('/^encrypted\s*=\s*\+/mi', $output) === 1
        ) {
            throw new ArchivePasswordProtectedException($output !== '' ? $output : 'архив защищён паролем');
        }

        throw new ArchiveUnsupportedException($output !== '' ? $output : 'распаковщик не смог обработать архив');
    }

    public static function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new ArchiveUnsupportedException("{$field} должен быть непустой строкой");
        }

        return $value;
    }

    public static function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new ArchiveUnsupportedException("{$field} должен быть неотрицательным целым числом");
        }

        return $value;
    }

    private static function timeout(?float $deadlineAt): int
    {
        $configured = TypedConfig::nonNegativeInt(
            'ai.prepare.archive.timeout_seconds',
            TypedConfig::nonNegativeInt('ai.tools.default_timeout_seconds', 180),
        );

        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = (int) floor($deadlineAt - microtime(true) - self::persistenceReserveSeconds());
        if ($remaining < 1) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }

        return min($configured, $remaining);
    }

    private static function persistenceReserveSeconds(): int
    {
        return TypedConfig::nonNegativeInt('documents.format_tools.persistence_seconds', 120);
    }
}
