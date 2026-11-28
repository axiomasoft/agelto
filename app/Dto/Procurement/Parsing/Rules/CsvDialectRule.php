<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use InvalidArgumentException;

/**
 * Диалект CSV-файла для разбора табличного листинга.
 */
final readonly class CsvDialectRule
{
    public function __construct(
        public string $delimiter = ';',
        public string $enclosure = '"',
        public string $escape = '\\',
        public string $encoding = 'UTF-8',
        public bool $stripBom = true,
        public bool $skipEmptyRows = true,
        public int $headerRowIndex = 0,
    ) {
        if (strlen($delimiter) !== 1) {
            throw new InvalidArgumentException('CsvDialectRule delimiter must be exactly 1 byte.');
        }

        if (strlen($enclosure) !== 1) {
            throw new InvalidArgumentException('CsvDialectRule enclosure must be exactly 1 byte.');
        }

        if (strlen($escape) > 1) {
            throw new InvalidArgumentException('CsvDialectRule escape must be 0 or 1 byte.');
        }

        if (trim($encoding) === '') {
            throw new InvalidArgumentException('CsvDialectRule encoding must not be empty.');
        }

        if ($headerRowIndex < 0) {
            throw new InvalidArgumentException('CsvDialectRule headerRowIndex must be >= 0.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            delimiter: RuleData::string($data, 'delimiter', ';'),
            enclosure: RuleData::string($data, 'enclosure', '"'),
            escape: RuleData::string($data, 'escape', '\\'),
            encoding: RuleData::string($data, 'encoding', 'UTF-8'),
            stripBom: RuleData::bool($data, 'strip_bom', true),
            skipEmptyRows: RuleData::bool($data, 'skip_empty_rows', true),
            headerRowIndex: RuleData::int($data, 'header_row_index', 0, 0),
        );
    }

    /**
     * @return array{
     *     delimiter: string,
     *     enclosure: string,
     *     escape: string,
     *     encoding: string,
     *     strip_bom: bool,
     *     skip_empty_rows: bool,
     *     header_row_index: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'escape' => $this->escape,
            'encoding' => $this->encoding,
            'strip_bom' => $this->stripBom,
            'skip_empty_rows' => $this->skipEmptyRows,
            'header_row_index' => $this->headerRowIndex,
        ];
    }
}
