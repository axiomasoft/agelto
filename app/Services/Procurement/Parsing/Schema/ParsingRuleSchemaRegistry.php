<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Schema;

use App\Enums\Procurement\Process\Core\StageCode;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Реестр версионированных JSON Schema контрактов для правил парсинга.
 */
final class ParsingRuleSchemaRegistry
{
    /** @var array<string, object> */
    private array $cache = [];

    /**
     * @return array{schemaId: string, schemaVersion: int, path: string}
     */
    public function resolve(StageCode $stage, int $schemaVersion = 1): array
    {
        if ($schemaVersion !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported parsing rule schema version %d.',
                $schemaVersion,
            ));
        }

        return match ($stage) {
            StageCode::Listing => [
                'schemaId' => 'agelto.parsing.listing.v1',
                'schemaVersion' => 1,
                'path' => resource_path('schemas/parsing/listing.v1.schema.json'),
            ],
            StageCode::Detail, StageCode::StatusRefresh => [
                'schemaId' => 'agelto.parsing.detail.v1',
                'schemaVersion' => 1,
                'path' => resource_path('schemas/parsing/detail.v1.schema.json'),
            ],
            default => throw new InvalidArgumentException(sprintf(
                'Stage "%s" does not support parsing rule schemas.',
                $stage->value,
            )),
        };
    }

    public function schemaIdFor(StageCode $stage, int $schemaVersion = 1): string
    {
        return $this->resolve($stage, $schemaVersion)['schemaId'];
    }

    public function loadSchemaObject(StageCode $stage, int $schemaVersion = 1): object
    {
        $descriptor = $this->resolve($stage, $schemaVersion);
        $cacheKey = $descriptor['schemaId'].':'.$descriptor['schemaVersion'];

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $path = $descriptor['path'];
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Parsing rule schema file not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw new RuntimeException(sprintf('Failed to read parsing rule schema file: %s', $path));
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON in parsing rule schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (! is_object($decoded)) {
            throw new RuntimeException(sprintf('Parsing rule schema "%s" must decode to a JSON object.', $path));
        }

        $this->assertLocalSchemaAst($decoded, '$');

        $this->cache[$cacheKey] = $decoded;

        return $decoded;
    }

    private function assertLocalSchemaAst(mixed $node, string $path): void
    {
        if (is_array($node)) {
            foreach ($node as $index => $item) {
                $this->assertLocalSchemaAst($item, $path.'['.$index.']');
            }

            return;
        }

        if (! is_object($node)) {
            return;
        }

        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($node);

        foreach (['$id', '$dynamicRef', '$recursiveRef'] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $vars)) {
                throw new InvalidArgumentException(sprintf(
                    'Parsing rule schema forbids non-local keyword "%s" at %s.',
                    $forbiddenKey,
                    $path,
                ));
            }
        }

        if (array_key_exists('$ref', $vars)) {
            $ref = $vars['$ref'];
            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                throw new InvalidArgumentException(sprintf(
                    'Parsing rule schema requires local "#/" $ref at %s.',
                    $path,
                ));
            }
        }

        foreach ($vars as $key => $value) {
            $this->assertLocalSchemaAst($value, $path.'.'.$key);
        }
    }
}
