<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class EndpointDefinition
{
    public function __construct(
        public string $code,
        public string $targetKey,
        public string $url,
        public RequestProfile $profile,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $code) !== 1 || $targetKey === '') {
            throw new InvalidPlatformDefinition('Invalid endpoint code or target key.');
        }
        CanonicalOrigin::fromUrl($url);
        if (parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidPlatformDefinition('Endpoint URL must not contain a fragment: '.$code);
        }
    }
}
