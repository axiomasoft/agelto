<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** DNS result checked for this physical hop; transport connects to this IP with original TLS name. */
final readonly class ResolvedUrl
{
    public function __construct(public SafeUrl $url, public string $address)
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Expected an IP address.');
        }
    }
}
