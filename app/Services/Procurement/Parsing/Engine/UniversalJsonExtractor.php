<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Rules\JsonDetailRule;
use App\Dto\Procurement\Parsing\Rules\JsonListingRule;
use App\Services\Procurement\Platform\Contracts\JsonPayloadExtractor;

/**
 * Универсальный экстрактор данных из JSON-ответов API по декларативным путям.
 */
final class UniversalJsonExtractor implements JsonPayloadExtractor
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int|null,
     *     next_cursor: string|null,
     * }
     */
    public function extractListing(array $payload, JsonListingRule $rule): array
    {
        $rawItems = data_get($payload, $rule->itemsPath);
        $items = [];

        if (is_array($rawItems)) {
            foreach ($rawItems as $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $mapped = [];
                foreach ($rule->fieldPaths as $field => $path) {
                    $mapped[$field] = data_get($rawItem, $path);
                }
                $items[] = $mapped;
            }
        }

        $totalRaw = $rule->totalPath !== null ? data_get($payload, $rule->totalPath) : null;
        $total = is_int($totalRaw) ? $totalRaw : (is_numeric($totalRaw) ? (int) $totalRaw : null);

        $cursorRaw = $rule->nextCursorPath !== null ? data_get($payload, $rule->nextCursorPath) : null;
        $nextCursor = is_string($cursorRaw) && $cursorRaw !== '' ? $cursorRaw : null;

        return [
            'items' => $items,
            'total' => $total,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     fields: array<string, mixed>,
     *     documents: list<array<string, mixed>>,
     *     events: list<array<string, mixed>>,
     * }
     */
    public function extractDetail(array $payload, JsonDetailRule $rule): array
    {
        $root = $rule->rootPath !== null ? data_get($payload, $rule->rootPath) : $payload;
        if (! is_array($root)) {
            return ['fields' => [], 'documents' => [], 'events' => []];
        }

        $fields = [];
        foreach ($rule->fieldPaths as $field => $path) {
            $fields[$field] = data_get($root, $path);
        }

        $documents = $this->extractNestedList($root, $rule->documentsPath, $rule->documentFieldPaths);
        $events = $this->extractNestedList($root, $rule->eventsPath, $rule->eventFieldPaths);

        return [
            'fields' => $fields,
            'documents' => $documents,
            'events' => $events,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $root
     * @param  array<string, string>  $fieldPaths
     * @return list<array<string, mixed>>
     */
    private function extractNestedList(array $root, ?string $listPath, array $fieldPaths): array
    {
        if ($listPath === null || $fieldPaths === []) {
            return [];
        }

        $rawList = data_get($root, $listPath);
        if (! is_array($rawList)) {
            return [];
        }

        $result = [];
        foreach ($rawList as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $mapped = [];
            foreach ($fieldPaths as $field => $path) {
                $mapped[$field] = data_get($entry, $path);
            }
            $result[] = $mapped;
        }

        return $result;
    }
}
