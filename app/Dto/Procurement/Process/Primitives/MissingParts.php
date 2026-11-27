<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

final readonly class MissingParts
{
    /** @var non-empty-list<string> */
    public array $values;

    /** @param array<array-key, string> $values */
    public function __construct(array $values)
    {
        if ($values === [] || ! array_is_list($values) || count(array_unique($values)) !== count($values)) {
            throw new \InvalidArgumentException('Missing parts must be a nonempty unique list.');
        }
        foreach ($values as $value) {
            ProcessValue::text($value);
        }
        $this->values = $values;
    }
}
