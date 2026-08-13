<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use RuntimeException;

/**
 * Down-конвертация канонической JSON Schema ({@see JsonSchemaGenerator}: `$defs`/`$ref`,
 * `type:[X,"null"]`, nested `additionalProperties:false`) в Gemini-совместимый `response_schema`
 * (OpenAPI-3.0-подмножество — ПОДТВЕРЖДЕНО чтением `vendor/prism-php/prism/src/Providers/Gemini/
 * Handlers/Structured.php:131`: Prism v0.100.1 шлёт схему именно в это поле, а не в
 * `responseJsonSchema`). `response_schema` не понимает `$ref`/`$defs` (нужна плоская inline-
 * структура) и `type`-массивы для nullable (нужен скалярный `type` + отдельный `nullable:true` —
 * та же семантика, что `SchemaMap::toArray()` Prism применяет для DSL-схем,
 * `vendor/.../Gemini/Maps/SchemaMap.php:71-73`); `additionalProperties` отбрасывается на ВСЕХ
 * уровнях, не только верхнем (P3 Known Deviations (1) ошибочно предполагал, что нижние уровни
 * не мешают — P3 phase-review, HIGH). `description` — ровно противоположная политика: канал
 * `response_schema` его поддерживает (`findings/P2-vendor-structured-output.md` §2), поэтому он
 * ПРОНОСИТСЯ насквозь на всех уровнях (объект, узел массива, ветка `anyOf`), а не отбрасывается
 * вместе с прочими непонятыми ключами (P2.4).
 *
 * `$ref`-циклы (самоссылка `TotalLine.children`, P2.3) Gemini `response_schema` принципиально не
 * может выразить — разворачиваются инлайном на глубину {@see self::MAX_SELF_DEPTH} вложений
 * одного и того же `$defs`-имени, глубже — свойство, замыкающее цикл, отбрасывается целиком
 * (модель просто не сможет вернуть более глубокую вложенность итогового блока). Глубина подобрана
 * без замера реальной вложенности итогов в боевых сметах — открытый риск, пересмотреть при первом
 * боевом расхождении (Escalation, P3 phase-review).
 */
final readonly class GeminiResponseSchemaAdapter
{
    private const int MAX_SELF_DEPTH = 3;

    /**
     * @param  array<string, mixed>  $schema  Вывод {@see JsonSchemaGenerator::generate()}
     * @return array<string, mixed>
     */
    public function adapt(array $schema): array
    {
        $defs = $this->definitions($schema['$defs'] ?? []);
        unset($schema['$defs']);

        return $this->resolveObject($schema, $defs, []);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $defs
     * @param  list<string>  $expandStack
     * @return array<string, mixed>|null null — узел неразрешим (глубина `$ref`-цикла исчерпана),
     *                                   свойство-владелец должно быть отброшено
     */
    private function resolveNode(array $node, array $defs, array $expandStack): ?array
    {
        $description = $node['description'] ?? null;
        unset($node['description']);

        if ($description !== null && ! is_string($description)) {
            throw new RuntimeException('Описание JSON Schema должно быть строкой.');
        }

        $resolved = $this->resolveDescribedNode($node, $defs, $expandStack);

        return $resolved === null || $description === null
            ? $resolved
            : ['description' => $description, ...$resolved];
    }

    /**
     * @param  array<string, mixed>  $node  узел БЕЗ `description` — снят и восстановлен вызывающим
     *                                      {@see resolveNode()} над результатом ЛЮБОЙ из веток ниже
     * @param  array<string, array<string, mixed>>  $defs
     * @param  list<string>  $expandStack
     * @return array<string, mixed>|null
     */
    private function resolveDescribedNode(array $node, array $defs, array $expandStack): ?array
    {
        if (isset($node['$ref'])) {
            $reference = $node['$ref'];

            if (! is_string($reference)) {
                throw new RuntimeException('JSON Schema $ref должен быть строкой.');
            }

            $name = basename($reference);

            if (! array_key_exists($name, $defs)) {
                throw new RuntimeException("JSON Schema \$ref указывает на отсутствующий \$defs: {$name}.");
            }

            if (count(array_filter($expandStack, static fn (string $s): bool => $s === $name)) >= self::MAX_SELF_DEPTH) {
                return null;
            }

            $resolved = $this->resolveObject($defs[$name], $defs, [...$expandStack, $name]);

            return $resolved;
        }

        if (isset($node['anyOf'])) {
            return $this->resolveAnyOf($node, $defs, $expandStack);
        }

        if (($node['type'] ?? null) === 'object') {
            return $this->resolveObject($node, $defs, $expandStack);
        }

        if (($node['type'] ?? null) === 'array') {
            $items = $this->resolveNode($this->schemaObject($node['items'] ?? null, 'items'), $defs, $expandStack);

            return $items === null ? null : ['type' => 'array', 'items' => $items];
        }

        if (is_array($node['type'] ?? null)) {
            return $this->withNullableType($node);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $defs
     * @param  list<string>  $expandStack
     * @return array<string, mixed>
     */
    private function resolveObject(array $node, array $defs, array $expandStack): array
    {
        $properties = [];
        $required = [];

        $propertySchemas = $node['properties'] ?? [];

        if (! is_array($propertySchemas)) {
            throw new RuntimeException('JSON Schema properties должен быть объектом.');
        }

        $requiredNames = $this->schemaStringList($node['required'] ?? [], 'required');

        foreach ($propertySchemas as $name => $propertySchema) {
            if (! is_string($name)) {
                throw new RuntimeException('Имя JSON Schema property должно быть строкой.');
            }

            $resolved = $this->resolveNode($this->schemaObject($propertySchema, "properties.{$name}"), $defs, $expandStack);

            if ($resolved === null) {
                continue;
            }

            $properties[$name] = $resolved;

            if (in_array($name, $requiredNames, true)) {
                $required[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /**
     * @param  array<string, mixed>  $node  {'anyOf': [ref-или-схема, {'type':'null'}]}
     * @param  array<string, array<string, mixed>>  $defs
     * @param  list<string>  $expandStack
     * @return array<string, mixed>|null
     */
    private function resolveAnyOf(array $node, array $defs, array $expandStack): ?array
    {
        $branches = [];

        $anyOf = $node['anyOf'] ?? null;

        if (! is_array($anyOf)) {
            throw new RuntimeException('JSON Schema anyOf должен быть списком объектов.');
        }

        foreach ($anyOf as $branch) {
            $branch = $this->schemaObject($branch, 'anyOf branch');

            if (($branch['type'] ?? null) === 'null') {
                $branches[] = $branch;

                continue;
            }

            $resolved = $this->resolveNode($branch, $defs, $expandStack);

            if ($resolved === null) {
                return null;
            }

            $branches[] = $resolved;
        }

        return ['anyOf' => $branches];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withNullableType(array $node): array
    {
        $types = array_values(array_filter(
            $this->schemaStringList($node['type'] ?? [], 'type'),
            static fn (string $type): bool => $type !== 'null',
        ));

        if ($types === []) {
            throw new RuntimeException('Nullable JSON Schema type должен содержать ненулевой тип.');
        }

        $node['type'] = $types[0];
        $node['nullable'] = true;

        return $node;
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(mixed $definitions): array
    {
        if (! is_array($definitions)) {
            throw new RuntimeException('JSON Schema $defs должен быть объектом.');
        }

        $result = [];

        foreach ($definitions as $name => $definition) {
            if (! is_string($name)) {
                throw new RuntimeException('Имя JSON Schema $defs должно быть строкой.');
            }

            $result[$name] = $this->schemaObject($definition, "\$defs.{$name}");
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function schemaObject(mixed $value, string $location): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("JSON Schema {$location} должен быть объектом.");
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new RuntimeException("Ключ JSON Schema {$location} должен быть строкой.");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<string> */
    private function schemaStringList(mixed $value, string $location): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("JSON Schema {$location} должен быть списком строк.");
        }

        $result = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new RuntimeException("JSON Schema {$location} должен содержать только строки.");
            }

            $result[] = $entry;
        }

        return $result;
    }
}
