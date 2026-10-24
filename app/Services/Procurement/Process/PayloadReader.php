<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

/** Strict JSON boundary reader: no coercion, missing fields or unrecognized keys. */
final readonly class PayloadReader
{
    /** @var array<array-key, mixed> */
    private array $fields;

    public function __construct(public \stdClass $object)
    {
        $this->fields = get_object_vars($object);
    }

    public static function json(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Malformed process JSON.', previous: $error);
        }
        if (! $object instanceof \stdClass) {
            throw new \InvalidArgumentException('Process payload must be a JSON object.');
        }

        return new self($object);
    }

    /** @param list<string> $keys */
    public function keys(array $keys): void
    {
        $actual = array_keys($this->fields);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('Process payload fields do not match its closed schema.');
        }
    }

    public function string(string $key): string
    {
        $value = $this->fields[$key] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Expected string field: '.$key);
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->fields[$key] ?? null;
        if (! is_int($value)) {
            throw new \InvalidArgumentException('Expected integer field: '.$key);
        }

        return $value;
    }

    public function object(string $key): self
    {
        $value = $this->fields[$key] ?? null;
        if (! $value instanceof \stdClass) {
            throw new \InvalidArgumentException('Expected object field: '.$key);
        }

        return new self($value);
    }

    public function isNull(string $key): bool
    {
        return array_key_exists($key, $this->fields) && $this->fields[$key] === null;
    }

    /** @return non-empty-list<string> */
    public function strings(string $key): array
    {
        $value = $this->fields[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException('Expected nonempty string list: '.$key);
        }
        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new \InvalidArgumentException('Expected string list member: '.$key);
            }
        }

        return $value;
    }
}
