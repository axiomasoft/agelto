<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Process\ProcessUuid;

/** Exact default/profile or explicit direct. Runtime secrets stay outside serializable plans. */
final readonly class RoutePolicy
{
    /** @var array<string, HttpRouteBinding> */
    public array $bindings;

    /** @param list<HttpRouteBinding> $bindings */
    public function __construct(array $bindings, public ?ProcessUuid $profileId = null, public bool $random = false)
    {
        $indexed = [];
        foreach ($bindings as $binding) {
            if (isset($indexed[$binding->id->value]) || ($profileId === null && $binding->proxy() !== '')) {
                throw new \InvalidArgumentException('Invalid exact route policy.');
            }
            $indexed[$binding->id->value] = $binding;
        }
        if ($profileId === null && count($indexed) !== 1) {
            throw new \InvalidArgumentException('Explicit direct requires one egress identity.');
        }
        $this->bindings = $indexed;
    }
}
