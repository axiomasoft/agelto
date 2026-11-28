<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use InvalidArgumentException;

/**
 * Правило разбора JSON-ответа листинга закупок.
 */
final readonly class JsonListingRule
{
    /**
     * @param  array<string, string>  $fieldPaths
     */
    public function __construct(
        public string $itemsPath,
        public array $fieldPaths,
        public ?string $totalPath = null,
        public ?string $nextCursorPath = null,
    ) {
        if (trim($itemsPath) === '') {
            throw new InvalidArgumentException('JsonListingRule itemsPath must not be empty.');
        }

        if ($fieldPaths === []) {
            throw new InvalidArgumentException('JsonListingRule fieldPaths must not be empty.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemsPath: RuleData::nonEmptyString($data, 'items_path'),
            fieldPaths: RuleData::stringMap($data, 'field_paths'),
            totalPath: RuleData::nullableString($data, 'total_path'),
            nextCursorPath: RuleData::nullableString($data, 'next_cursor_path'),
        );
    }

    /**
     * @return array{
     *     items_path: string,
     *     field_paths: array<string, string>,
     *     total_path: string|null,
     *     next_cursor_path: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'items_path' => $this->itemsPath,
            'field_paths' => $this->fieldPaths,
            'total_path' => $this->totalPath,
            'next_cursor_path' => $this->nextCursorPath,
        ];
    }
}
