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
 * элементов массивов. Схема держится плоской и компактной: без description-дублей (семантика уже
 * в prompt-контексте, PromptContextGenerator), рекурсия (`TotalLine.children`) — через `$defs`/
 * `$ref`, не инлайн. Выход стабилен между запусками — база rules_hash и implicit-cache префикса.
 */
final readonly class JsonSchemaGenerator
{
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

        $properties = [];
        $required = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $properties[$name] = $this->parameterSchema($parameter, $constructor, $defs);

            if (! $parameter->isDefaultValueAvailable() && ! $this->allowsNull($parameter)) {
                $required[] = $name;
            }
        }

        ksort($properties);
        sort($required);

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
            enum_exists($typeName) => $this->enumSchema($typeName),
            is_subclass_of($typeName, Data::class) => $this->dataClassRef($typeName, $defs),
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
        $itemType = $this->docblockArrayItemType($parameter->getName(), $constructor);

        if ($itemType === null) {
            throw new RuntimeException("Не найден тип элементов массива \${$parameter->getName()} в докблоке @param.");
        }

        return [
            'type' => 'array',
            'items' => $this->namedTypeSchema($itemType, $constructor->getDeclaringClass(), $defs),
        ];
    }

    private function docblockArrayItemType(string $paramName, ReflectionMethod $constructor): ?string
    {
        $doc = $constructor->getDocComment();

        if ($doc === false) {
            return null;
        }

        if (preg_match('/@param\s+([^\s\[]+)\[\]\s+\$'.preg_quote($paramName, '/').'\b/', $doc, $matches) !== 1) {
            return null;
        }

        return $this->resolveClassName($matches[1], $constructor->getDeclaringClass());
    }

    private function resolveClassName(string $shortType, ReflectionClass $declaringClass): string
    {
        if (in_array($shortType, ['string', 'int', 'float', 'bool'], true)) {
            return $shortType;
        }

        $candidate = $declaringClass->getNamespaceName().'\\'.$shortType;

        return class_exists($candidate) || enum_exists($candidate) ? $candidate : $shortType;
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
