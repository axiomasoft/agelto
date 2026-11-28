<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Llm\Provider;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Provider::class)]
enum ProviderPermission: string
{
    case ViewAny = 'provider.view_any';
    case View = 'provider.view';
    case Create = 'provider.create';
    case Update = 'provider.update';
    case Delete = 'provider.delete';
    case Restore = 'provider.restore';
    case ForceDelete = 'provider.force_delete';
    case Replicate = 'provider.replicate';
    case Reorder = 'provider.reorder';
}
