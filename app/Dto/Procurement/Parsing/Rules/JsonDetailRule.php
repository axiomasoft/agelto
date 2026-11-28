<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use InvalidArgumentException;

/**
 * Правило разбора JSON-представления карточки закупки.
 */
final readonly class JsonDetailRule
{
    /**
     * @param  array<string, string>  $fieldPaths
     * @param  array<string, string>  $documentFieldPaths
     * @param  array<string, string>  $eventFieldPaths
     */
    public function __construct(
        public ?string $rootPath = null,
        public array $fieldPaths = [],
        public ?string $documentsPath = null,
        public array $documentFieldPaths = [],
        public ?string $eventsPath = null,
        public array $eventFieldPaths = [],
    ) {
        if ($fieldPaths === []) {
            throw new InvalidArgumentException('JsonDetailRule fieldPaths must not be empty.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rootPath: RuleData::nullableString($data, 'root_path'),
            fieldPaths: RuleData::stringMap($data, 'field_paths'),
            documentsPath: RuleData::nullableString($data, 'documents_path'),
            documentFieldPaths: RuleData::stringMap($data, 'document_field_paths'),
            eventsPath: RuleData::nullableString($data, 'events_path'),
            eventFieldPaths: RuleData::stringMap($data, 'event_field_paths'),
        );
    }

    /**
     * @return array{
     *     root_path: string|null,
     *     field_paths: array<string, string>,
     *     documents_path: string|null,
     *     document_field_paths: array<string, string>,
     *     events_path: string|null,
     *     event_field_paths: array<string, string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'root_path' => $this->rootPath,
            'field_paths' => $this->fieldPaths,
            'documents_path' => $this->documentsPath,
            'document_field_paths' => $this->documentFieldPaths,
            'events_path' => $this->eventsPath,
            'event_field_paths' => $this->eventFieldPaths,
        ];
    }
}
