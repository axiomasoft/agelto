<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Llm\Schema\ProviderSchema;
use stdClass;

/** Compiles CanonicalSchema into OpenAI Strict Structured Outputs JSON Schema (strict: true). */
final class OpenAiStrictSchemaCompiler
{
    private const array STRIPPED_KEYWORDS = [
        'additionalProperties',
        'pattern',
        'minimum',
        'maximum',
        'minItems',
        'maxItems',
        'minLength',
        'maxLength',
    ];

    public function compile(CanonicalSchema $schema): ProviderSchema
    {
        (new CanonicalSchemaValidator)->assertSchema($schema);
        $local = [];
        $definition = $this->compileNode($schema->object(), $schema->object(), '#', $local, []);

        return new ProviderSchema($definition, $local, $schema->hash);
    }

    /**
     * Transforms an already-compiled ProviderSchema definition into OpenAI strict mode.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function strictify(array $definition): array
    {
        $local = [];

        return $this->strictifyArrayNode($definition, '#', $local);
    }

    /**
     * @param  list<string>  $local
     * @param  list<string>  $visitedRefs
     * @return array<string, mixed>
     */
    private function compileNode(stdClass $node, stdClass $root, string $path, array &$local, array $visitedRefs): array
    {
        $result = [];
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, ['$schema', '$defs'], true)) {
                continue;
            }
            if ($key === '$ref' && is_string($value)) {
                if (in_array($value, $visitedRefs, true)) {
                    throw new \InvalidArgumentException('Cyclic schema reference: '.$value);
                }
                $defs = $root->{'$defs'} ?? null;
                $name = substr($value, 8);
                $target = $defs instanceof stdClass ? ($defs->{$name} ?? null) : null;
                if (! $target instanceof stdClass) {
                    throw new \InvalidArgumentException('Unsupported schema reference.');
                }
                $result = [...$result, ...$this->compileNode($target, $root, $path, $local, [...$visitedRefs, $value])];
            } elseif ($key === 'properties' && $value instanceof stdClass) {
                $properties = [];
                foreach (get_object_vars($value) as $name => $property) {
                    if (! $property instanceof stdClass) {
                        throw new \InvalidArgumentException('Invalid property schema.');
                    }
                    $properties[$name] = $this->compileNode($property, $root, $path.'/'.$name, $local, $visitedRefs);
                }
                $result[$key] = $properties;
            } elseif ($key === 'items' && $value instanceof stdClass) {
                $result[$key] = $this->compileNode($value, $root, $path.'/items', $local, $visitedRefs);
            } elseif ($key === 'anyOf' && is_array($value)) {
                $branches = [];
                foreach ($value as $index => $branch) {
                    if (! $branch instanceof stdClass) {
                        throw new \InvalidArgumentException('Invalid union branch.');
                    }
                    $branches[] = $this->compileNode($branch, $root, $path.'/anyOf/'.$index, $local, $visitedRefs);
                }
                $result[$key] = $branches;
            } elseif (in_array($key, ['type', 'enum', 'description', 'required'], true)) {
                $result[$key] = $value;
            } elseif (in_array($key, self::STRIPPED_KEYWORDS, true)) {
                $local[] = $path.'/'.$key;
            } else {
                throw new \InvalidArgumentException('Unsupported wire schema keyword: '.$path.'/'.$key);
            }
        }

        return $this->applyStrictObjectRules($result);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $local
     * @return array<string, mixed>
     */
    private function strictifyArrayNode(array $node, string $path, array &$local): array
    {
        $result = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::STRIPPED_KEYWORDS, true)) {
                $local[] = $path.'/'.$key;

                continue;
            }
            if ($key === 'properties' && is_array($value)) {
                $properties = [];
                foreach ($value as $name => $property) {
                    if (is_array($property)) {
                        $properties[(string) $name] = $this->strictifyArrayNode($this->stringKeyed($property), $path.'/'.$name, $local);
                    }
                }
                $result[$key] = $properties;
            } elseif ($key === 'items' && is_array($value)) {
                $result[$key] = $this->strictifyArrayNode($this->stringKeyed($value), $path.'/items', $local);
            } elseif ($key === 'anyOf' && is_array($value)) {
                $branches = [];
                foreach ($value as $index => $branch) {
                    if (is_array($branch)) {
                        $branches[] = $this->strictifyArrayNode($this->stringKeyed($branch), $path.'/anyOf/'.$index, $local);
                    }
                }
                $result[$key] = $branches;
            } else {
                $result[$key] = $value;
            }
        }

        return $this->applyStrictObjectRules($result);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function applyStrictObjectRules(array $node): array
    {
        $isObject = ($node['type'] ?? null) === 'object' || array_key_exists('properties', $node);
        if (! $isObject) {
            return $node;
        }
        $node['additionalProperties'] = false;
        if (! isset($node['properties']) || ! is_array($node['properties'])) {
            return $node;
        }
        $existingRequired = is_array($node['required'] ?? null) ? $node['required'] : [];
        $allKeys = [];
        foreach ($node['properties'] as $name => $propertySchema) {
            $propKey = (string) $name;
            $allKeys[] = $propKey;
            if (! in_array($propKey, $existingRequired, true) && is_array($propertySchema)) {
                $node['properties'][$name] = $this->makeNullable($this->stringKeyed($propertySchema));
            }
        }
        $node['required'] = $allKeys;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function makeNullable(array $schema): array
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                if (is_array($branch) && ($branch['type'] ?? null) === 'null') {
                    return $schema;
                }
            }
            $schema['anyOf'][] = ['type' => 'null'];

            return $schema;
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (! in_array(null, $schema['enum'], true)) {
                return ['anyOf' => [$schema, ['type' => 'null']]];
            }

            return $schema;
        }
        if (isset($schema['type'])) {
            if (is_string($schema['type'])) {
                if ($schema['type'] !== 'null') {
                    $schema['type'] = [$schema['type'], 'null'];
                }

                return $schema;
            }
            if (is_array($schema['type'])) {
                if (! in_array('null', $schema['type'], true)) {
                    $schema['type'][] = 'null';
                }

                return $schema;
            }
        }

        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }
}
