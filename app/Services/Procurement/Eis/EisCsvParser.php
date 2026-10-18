<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

/** Strict CSV records: quoted newlines and doubled quotes are data, never record boundaries. */
final readonly class EisCsvParser
{
    /** @return list<array<string, string>> */
    public function parse(string $body): array
    {
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

            foreach ($this->records($stream) as $values) {
                if ($header === null) {
                    $header = array_map(trim(...), $values);
                    $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]) ?? $header[0];

                    if (in_array('', $header, true) || count(array_unique($header)) !== count($header)
                        || array_diff(['Реестровый номер закупки', 'Дата размещения', 'Дата обновления'], $header) !== []) {
                        throw new \UnexpectedValueException('Invalid EIS CSV header.');
                    }

                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new \UnexpectedValueException('Invalid EIS CSV record width.');
                }

                $rows[] = array_combine($header, $values);
            }

            if ($header === null) {
                throw new \UnexpectedValueException('Missing EIS CSV header.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return \Generator<int, list<string>>
     */
    private function records($stream): \Generator
    {
        $fields = [];
        $field = '';
        $state = 'start';
        $recordStarted = false;
        $afterCr = false;

        while (($char = fgetc($stream)) !== false) {
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
