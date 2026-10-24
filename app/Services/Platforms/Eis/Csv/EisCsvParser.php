<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Csv;

use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Services\Procurement\BoundedResponseReader;

/** Strict CSV records: quoted newlines and doubled quotes are data, never record boundaries. */
final readonly class EisCsvParser
{
    /** @return list<array<string, string>> */
    public function parse(string $body): array
    {
        $batch = $this->parseBatch($body);
        $header = $batch['header'];
        if ($header === null || in_array('', $header, true)
            || count(array_unique($header)) !== count($header)
            || array_diff(['Реестровый номер закупки', 'Дата размещения', 'Дата обновления'], $header) !== []) {
            throw new \UnexpectedValueException('Invalid EIS CSV header.');
        }
        if ($batch['malformed'] > 0) {
            throw new \UnexpectedValueException('Invalid EIS CSV record width.');
        }

        return $batch['rows'];
    }

    /** @return array{rows: list<array<string, string>>, records: int, malformed: int, header: list<string>|null} */
    public function parseBatch(string $body, ?float $deadlineAt = null, int $maxRecords = 1000, int $maxFieldBytes = 262_144): array
    {
        if (substr_count($body, ';') > 128_000) {
            throw new SourceResponseLimitExceeded('CSV separator count exceeds the resource limit.');
        }
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new \RuntimeException('Cannot open CSV stream.');
        }

        try {
            if (fwrite($stream, $body) !== strlen($body)) {
                throw new \RuntimeException('Incomplete CSV stream write.');
            }
            rewind($stream);
            $header = null;
            $rows = [];
            $records = 0;
            $malformed = 0;

            foreach ($this->records($stream, $deadlineAt, $maxFieldBytes) as $values) {
                BoundedResponseReader::assertTime($deadlineAt);
                if (count($values) > 256) {
                    throw new SourceResponseLimitExceeded('CSV column count exceeds the resource limit.');
                }
                foreach ($values as $value) {
                    if (strlen($value) > $maxFieldBytes) {
                        throw new SourceResponseLimitExceeded('CSV field exceeds the configured byte limit.');
                    }
                }
                if ($header === null) {
                    $header = array_map(trim(...), $values);
                    $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]) ?? $header[0];

                    if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) {
                        throw new \UnexpectedValueException('Invalid EIS CSV header.');
                    }

                    continue;
                }

                $records++;
                if ($records > $maxRecords) {
                    throw new SourceResponseLimitExceeded('CSV record count exceeds the configured limit.');
                }
                if (count($values) !== count($header)) {
                    $malformed++;

                    continue;
                }

                $rows[] = array_combine($header, $values);
            }

            return ['rows' => $rows, 'records' => $records, 'malformed' => $malformed, 'header' => $header];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return \Generator<int, list<string>>
     */
    private function records($stream, ?float $deadlineAt, int $maxFieldBytes): \Generator
    {
        $fields = [];
        $field = '';
        $state = 'start';
        $recordStarted = false;
        $afterCr = false;
        $bytes = 0;

        while (($char = fgetc($stream)) !== false) {
            if (++$bytes % 8192 === 0) {
                BoundedResponseReader::assertTime($deadlineAt);
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
                if ($char === '"') {
                    $state = 'closed';
                } else {
                    $field .= $char;
                }

                continue;
            }

            if ($state === 'closed' && $char === '"') {
                $field .= '"';
                $state = 'quoted';

                continue;
            }

            if ($char === ';' || $char === "\r" || $char === "\n") {
                if ($char === ';') {
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

            if ($state === 'closed' || ($char === '"' && $state !== 'start')) {
                throw new \UnexpectedValueException('Invalid EIS CSV quoting.');
            }

            $recordStarted = true;
            if ($char === '"') {
                $state = 'quoted';
            } else {
                $field .= $char;
                $state = 'plain';
            }
        }

        if (! feof($stream)) {
            throw new \RuntimeException('Incomplete CSV stream read.');
        }

        if ($state === 'quoted') {
            throw new \UnexpectedValueException('Unterminated EIS CSV field.');
        }

        if ($recordStarted) {
            $fields[] = $field;
            yield $fields;
        }
    }
}
