<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Llm\Schema\ProviderSchema;
use stdClass;

/** Explicit flat subset for the staged transport; no truncation or silent constraint loss. */
final class ProviderSchemaCompiler
{
    public function compile(CanonicalSchema $schema): ProviderSchema
    {
        (new CanonicalSchemaValidator)->assertSchema($schema);
        $local = [];
        $definition = $this->node($schema->object(), $schema->object(), '#', $local);

        return new ProviderSchema($definition, $local, $schema->hash);
    }

    /**
     * @param  list<string>  $local
     * @return array<string, mixed>
     */
    private function node(stdClass $node, stdClass $root, string $path, array &$local): array
    {
        $result = [];
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, ['$schema', '$defs'], true)) {
                continue;
            }
            if ($key === '$ref' && is_string($value)) {
                $defs = $root->{'$defs'} ?? null;
                $name = substr($value, 8);
                $target = $defs instanceof stdClass ? ($defs->{$name} ?? null) : null;
                if (! $target instanceof stdClass) {
                    throw new \InvalidArgumentException('Unsupported schema reference.');
                }
                $result = [...$result, ...$this->node($target, $root, $path, $local)];
            } elseif ($key === 'properties' && $value instanceof stdClass) {
                $properties = [];
                foreach (get_object_vars($value) as $name => $property) {
                    if (! $property instanceof stdClass) {
                        throw new \InvalidArgumentException('Invalid property schema.');
                    }
                    $properties[$name] = $this->node($property, $root, $path.'/'.$name, $local);
                }
                $result[$key] = $properties;
            } elseif ($key === 'items' && $value instanceof stdClass) {
                $result[$key] = $this->node($value, $root, $path.'/items', $local);
            } elseif ($key === 'anyOf' && is_array($value)) {
                $branches = [];
                foreach ($value as $index => $branch) {
                    if (! $branch instanceof stdClass) {
                        throw new \InvalidArgumentException('Invalid union branch.');
                    }
                    $branches[] = $this->node($branch, $root, $path.'/anyOf/'.$index, $local);
                }
                $result[$key] = $branches;
            } elseif (in_array($key, ['type', 'enum', 'description', 'required'], true)) {
                $result[$key] = $value;
            } elseif (in_array($key, ['additionalProperties', 'pattern', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength'], true)) {
                $local[] = $path.'/'.$key;
            } else {
                throw new \InvalidArgumentException('Unsupported wire schema keyword: '.$path.'/'.$key);
            }
        }

        return $result;
    }
}
