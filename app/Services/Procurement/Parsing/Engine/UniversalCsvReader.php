<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Rules\CsvDialectRule;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use Closure;
use Generator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Универсальный потоковый читатель CSV/TSV-таблиц по декларативному диалекту.
 */
final class UniversalCsvReader implements TabularReader
{
    private const int READ_BYTES = 64 * 1024;

    /**
     * @param  (Closure(): void)|null  $tick
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function read(
        string $content,
        CsvDialectRule $dialect,
        ?float $deadlineAt = null,
        int $maxRecords = 1000,
        int $maxFieldBytes = 262_144,
        ?Closure $tick = null,
    ): array {
        $normalized = $content;

        if ($dialect->stripBom && str_starts_with($normalized, "\xEF\xBB\xBF")) {
            $normalized = substr($normalized, 3);
        }

        if (strtoupper($dialect->encoding) !== 'UTF-8') {
            $converted = mb_convert_encoding($normalized, 'UTF-8', $dialect->encoding);
            if (is_string($converted)) {
                $normalized = $converted;
            }
        }

        if (substr_count($normalized, $dialect->delimiter) > 128_000) {
            throw new SourceResponseLimitExceeded('CSV separator count exceeds the resource limit.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open temporary stream for CSV reading.');
        }

        try {
            if (fwrite($stream, $normalized) !== strlen($normalized)) {
                throw new RuntimeException('Incomplete CSV stream write.');
            }
            rewind($stream);

            $allRows = [];
            $records = 0;

            foreach ($this->records($stream, $dialect->delimiter, $dialect->enclosure, $deadlineAt, $maxFieldBytes, $tick) as $cells) {
                self::assertTime($deadlineAt);

                if (count($cells) > 256) {
                    throw new SourceResponseLimitExceeded('CSV column count exceeds the resource limit.');
                }

                foreach ($cells as $cell) {
                    if (strlen($cell) > $maxFieldBytes) {
                        throw new SourceResponseLimitExceeded('CSV field exceeds the configured byte limit.');
                    }
                }

                if ($dialect->skipEmptyRows && $this->isRowEmpty($cells)) {
                    continue;
                }

                $allRows[] = $cells;
                $records++;

                if (count($allRows) > $dialect->headerRowIndex + 1 && ($records - ($dialect->headerRowIndex + 1)) > $maxRecords) {
                    throw new SourceResponseLimitExceeded('CSV record count exceeds the configured limit.');
                }
            }
        } finally {
            fclose($stream);
        }

        if ($allRows === [] || ! isset($allRows[$dialect->headerRowIndex])) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(trim(...), $allRows[$dialect->headerRowIndex]);
        $rows = array_slice($allRows, $dialect->headerRowIndex + 1);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private static function assertTime(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            throw OutboundRequestDeadlineExceeded::forStage(stage: 'response-body');
        }
    }

    /**
     * @param  resource  $stream
     * @param  (Closure(): void)|null  $tick
     * @return Generator<int, list<string>>
     */
    private function records(
        $stream,
        string $delimiter,
        string $enclosure,
        ?float $deadlineAt,
        int $maxFieldBytes,
        ?Closure $tick,
    ): Generator {
        $fields = [];
        $field = '';
        $state = 'start';
        $recordStarted = false;
        $afterCr = false;
        $bytes = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, self::READ_BYTES);
            if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                throw new RuntimeException('Incomplete CSV stream read.');
            }
            $length = strlen($chunk);
            for ($index = 0; $index < $length; $index++) {
                $char = $chunk[$index];
                if (++$bytes % 8192 === 0) {
                    $tick?->__invoke();
                    self::assertTime($deadlineAt);
                }
                if (strlen($field) > $maxFieldBytes || count($fields) > 255) {
                    throw new SourceResponseLimitExceeded('CSV field or column count exceeds the configured limit.');
                }
                if ($afterCr && $char === "\n") {
                    $afterCr = false;

                    continue;
                }
                $afterCr = false;

                if ($state === 'quoted') {
                    if ($char === $enclosure) {
                        $state = 'closed';
                    } else {
                        $field .= $char;
                    }

                    continue;
                }

                if ($state === 'closed' && $char === $enclosure) {
                    $field .= $enclosure;
                    $state = 'quoted';

                    continue;
                }

                if ($char === $delimiter || $char === "\r" || $char === "\n") {
                    if ($char === $delimiter) {
                        $fields[] = $field;
                        $recordStarted = true;
                    } else {
                        $fields[] = $field;
                        yield $fields;
                        $fields = [];
                        $recordStarted = false;
                        $afterCr = $char === "\r";
                    }
                    $field = '';
                    $state = 'start';

                    continue;
                }

                if ($state === 'closed' || ($char === $enclosure && $state !== 'start')) {
                    throw new UnexpectedValueException('Invalid CSV quoting.');
                }

                $recordStarted = true;
                if ($char === $enclosure) {
                    $state = 'quoted';
                } else {
                    $field .= $char;
                    $state = 'plain';
                }
            }
        }

        if ($state === 'quoted') {
            throw new UnexpectedValueException('Unterminated CSV field.');
        }

        if ($recordStarted) {
            $fields[] = $field;
            yield $fields;
        }
    }

    /**
     * @param  list<string>  $cells
     */
    private function isRowEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
