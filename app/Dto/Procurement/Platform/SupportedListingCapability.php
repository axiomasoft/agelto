<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

/** Declaration only; implementations are reflected, never constructed during validation. */
final readonly class SupportedListingCapability
{
    /** @var list<string> */
    public array $endpoints;

    /** @param array<array-key, mixed> $endpoints */
    public function __construct(public string $implementation, array $endpoints)
    {
        if ($implementation === '' || ! array_is_list($endpoints) || $endpoints === []) {
            throw new InvalidPlatformDefinition('Supported Listing requires an implementation and endpoints.');
        }
        foreach ($endpoints as $endpoint) {
            if (! is_string($endpoint) || $endpoint === '') {
                throw new InvalidPlatformDefinition('Invalid Listing endpoint declaration.');
            }
        }
        if (count(array_unique($endpoints)) !== count($endpoints)) {
            throw new InvalidPlatformDefinition('Duplicate Listing endpoint declaration.');
        }
        $this->endpoints = $endpoints;
    }
}
