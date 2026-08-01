<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Reflection-маппер Data-класса → `responseJsonSchema` для structured output (docs/specs/v2/
 * 08-estimate-extraction.md §6; research/E §2). Нативной генерации JSON Schema в
 * spatie/laravel-data нет — маппинг ручной по конструктору + докблоку `@param Type[] $prop` для
 * элементов массивов. `description` каждого свойства берётся из `#[Description]` через
 * `ContractAttributeReader` (P2.3/P2.4) — единственный носитель семантики поля, второго канала
 * (печать исходника в промт) для описаний больше нет. Рекурсия (`TotalLine.children`) — через
 * `$defs`/`$ref`, не инлайн. Выход стабилен между запусками — база rules_hash и implicit-cache
 * префикса: носитель детерминизма свойств/`required` — порядок конструктора, а не алфавит (D89).
 *
 * Правило «что считается вложенным типом» здесь НЕ живёт: оно принадлежит `DataTypeGraph` (P6.1),
 * общему с блоком схем промта. Этот класс отвечает только за ФОРМУ схемы.
 */
final readonly class JsonSchemaGenerator
{
    public function __construct(
        private DataTypeGraph $types = new DataTypeGraph,
        private ContractAttributeReader $descriptions = new ContractAttributeReader,
    ) {}

    /**
     * @param  class-string<Data>  $class
     * @return array<string, mixed>
     */
    public function generate(string $class): array
    {
        $defs = [];
        $root = $this->classSchema($class, $defs);

        if ($defs === []) {
            return $root;
        }

        ksort($defs);

        return ['$defs' => $defs, ...$root];
    }

    /**
     * @param  class-string<Data>  $class
     * @param  array<string, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    private function classSchema(string $class, array &$defs): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $descriptions = $this->descriptions->descriptions($class);

        $properties = [];
        $required = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $schema = $this->parameterSchema($parameter, $constructor, $defs);

            $properties[$name] = isset($descriptions[$name])
                ? ['description' => $descriptions[$name], ...$schema]
                : $schema;

            if (! $parameter->isDefaultValueAvailable() && ! $this->allowsNull($parameter)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    private function parameterSchema(ReflectionParameter $parameter, ReflectionMethod $constructor, array &$defs): array
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException("Неподдерживаемый (union/intersection) тип параметра \${$parameter->getName()}.");
        }

        if ($type->getName() === 'array') {
            return $this->arraySchema($parameter, $constructor, $defs);
        }

        $schema = $this->namedTypeSchema($type->getName(), $constructor->getDeclaringClass(), $defs);

        return $this->allowsNull($parameter) ? $this->withNull($schema) : $schema;
    }

    /**
     * @param  array<string, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    private function namedTypeSchema(string $typeName, ReflectionClass $context, array &$defs): array
    {
        return match (true) {
            $typeName === 'string' => ['type' => 'string'],
            $typeName === 'int' => ['type' => 'integer'],
            $typeName === 'float' => ['type' => 'number'],
            $typeName === 'bool' => ['type' => 'boolean'],
            $this->types->isEnum($typeName) => $this->enumSchema($typeName),
            $this->types->isDataClass($typeName) => $this->dataClassRef($typeName, $defs),
            default => throw new RuntimeException("Неподдерживаемый тип {$typeName} (контекст {$context->getName()})."),
        };
    }

    /**
     * @param  class-string<\UnitEnum&\BackedEnum>  $enum
     * @return array<string, mixed>
     */
    private function enumSchema(string $enum): array
    {
        $values = array_map(static fn (\BackedEnum $case): string|int => $case->value, $enum::cases());

        return ['type' => 'string', 'enum' => $values];
    }

    /**
     * @param  class-string<Data>  $class
     * @param  array<string, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    private function dataClassRef(string $class, array &$defs): array
    {
        $key = new ReflectionClass($class)->getShortName();

        if (! isset($defs[$key])) {
            // Резервируем ключ до рекурсивного вызова — TotalLine ссылается сама на себя (children).
            $defs[$key] = ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
            $defs[$key] = $this->classSchema($class, $defs);
        }

        return ['$ref' => "#/\$defs/{$key}"];
    }

    /**
     * @param  array<string, array<string, mixed>>  $defs
     * @return array<string, mixed>
     */
    private function arraySchema(ReflectionParameter $parameter, ReflectionMethod $constructor, array &$defs): array
    {
        $itemType = $this->types->docblockArrayItemType($parameter, $constructor);

        if ($itemType === null) {
            throw new RuntimeException("Не найден тип элементов массива \${$parameter->getName()} в докблоке @param.");
        }

        return [
            'type' => 'array',
            'items' => $this->namedTypeSchema($itemType, $constructor->getDeclaringClass(), $defs),
        ];
    }

    private function allowsNull(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->allowsNull();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withNull(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        $schema['type'] = [$schema['type'], 'null'];

        return $schema;
    }
}
