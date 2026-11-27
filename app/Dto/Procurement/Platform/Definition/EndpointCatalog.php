<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class EndpointCatalog
{
    /** @var array<string, EndpointDefinition> */
    public array $endpoints;

    /** @param array<array-key, mixed> $endpoints */
    public function __construct(array $endpoints)
    {
        if (! array_is_list($endpoints)) {
            throw new InvalidPlatformDefinition('Endpoints must be a list.');
        }
        $byCode = [];
        foreach ($endpoints as $endpoint) {
            if (! $endpoint instanceof EndpointDefinition) {
                throw new InvalidPlatformDefinition('Expected typed EndpointDefinition.');
            }
            if (isset($byCode[$endpoint->code])) {
                throw new InvalidPlatformDefinition('Duplicate endpoint: '.$endpoint->code);
            }
            $byCode[$endpoint->code] = $endpoint;
        }
        ksort($byCode, SORT_STRING);
        $this->endpoints = $byCode;
    }

    public function get(string $code): EndpointDefinition
    {
        return $this->endpoints[$code] ?? throw new InvalidPlatformDefinition('Unknown endpoint: '.$code);
    }
}
