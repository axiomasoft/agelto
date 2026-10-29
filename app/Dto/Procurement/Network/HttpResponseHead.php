<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Received HTTP facts, independent of entity transfer, storage and text validation. */
final readonly class HttpResponseHead
{
    /** @var array<string, list<string>> */
    public array $headers;

    /** @param array<string, list<string>> $headers */
    public function __construct(public int $status, array $headers)
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('HTTP status out of range.');
        }
        $normalized = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1) {
                throw new \InvalidArgumentException('Invalid response header name.');
            }
            $normalized[$lower] = [...($normalized[$lower] ?? []), ...$values];
        }
        ksort($normalized, SORT_STRING);
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
