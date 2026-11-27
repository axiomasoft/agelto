<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Egress route: direct (configured egress_id) либо proxy node. Implicit direct fallback запрещён. */
enum RouteKind: string
{
    case Direct = 'direct';
    case Proxy = 'proxy';
}
