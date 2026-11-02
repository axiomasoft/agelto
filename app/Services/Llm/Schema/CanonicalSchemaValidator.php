<?php

declare(strict_types=1);

namespace App\Services\Llm\Schema;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Llm\Schema\SchemaValidationResult;
use Opis\JsonSchema\CompliantValidator;
use stdClass;

/** Opis owns validation. Only trusted, bounded local definitions may reach its loader. */
final class CanonicalSchemaValidator
{
    public function validate(string $raw, CanonicalSchema $schema): SchemaValidationResult
    {
        $this->assertSchema($schema);
        if (strlen($raw) > 1048576) {
            return new SchemaValidationResult(['response_size_limit']);
        }
        try {
            $data = json_decode($raw, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new SchemaValidationResult(['invalid_json']);
        }
        $started = hrtime(true);
        // No URI handlers, directories or remote resolvers are registered.
        $validator = new CompliantValidator;
        $validator->setMaxErrors(16);
        $result = $validator->validate($data, $schema->object());
        if (hrtime(true) - $started > 250_000_000) {
            return new SchemaValidationResult(['validation_time_limit']);
        }

        return new SchemaValidationResult($result->isValid() ? [] : ['schema:'.($result->error()?->keyword() ?? 'invalid')]);
    }

    public function assertSchema(CanonicalSchema $schema): void
    {
        $root = $schema->object();
        $nodes = 0;
        $this->inspect($root, $root, [], 0, $nodes);
        // Compile before any paid operation. Local refs were checked above.
        (new CompliantValidator)->loader()->loadObjectSchema($root);
    }

    /** @param list<string> $refs */
    private function inspect(mixed $node, stdClass $root, array $refs, int $depth, int &$nodes): void
    {
        if (++$nodes > 4096 || $depth > 32) {
            throw new \InvalidArgumentException('Schema complexity limit.');
        }
        if ($node instanceof stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if (in_array($key, ['$id', '$dynamicRef', '$recursiveRef'], true)) {
                    throw new \InvalidArgumentException('Only root-local schema references are supported.');
                }
                if ($key === '$ref') {
                    if (! is_string($value) || ! str_starts_with($value, '#/$defs/') || in_array($value, $refs, true)) {
                        throw new \InvalidArgumentException('External or recursive schema reference.');
                    }
                    $name = substr($value, 8);
                    $defs = $root->{'$defs'} ?? null;
                    if (! $defs instanceof stdClass || ! property_exists($defs, $name)) {
                        throw new \InvalidArgumentException('Unknown local schema reference.');
                    }
                    $this->inspect($defs->{$name}, $root, [...$refs, $value], $depth + 1, $nodes);
                } else {
                    $this->inspect($value, $root, $refs, $depth + 1, $nodes);
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                $this->inspect($value, $root, $refs, $depth + 1, $nodes);
            }
        }
    }
}
