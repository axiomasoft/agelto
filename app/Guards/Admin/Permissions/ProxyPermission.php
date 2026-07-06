<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum ProxyPermission: string
{
    case ViewAny = 'proxy.view_any';
    case View = 'proxy.view';
    case Create = 'proxy.create';
    case Update = 'proxy.update';
    case Delete = 'proxy.delete';
    case Restore = 'proxy.restore';
    case ForceDelete = 'proxy.force_delete';
    case Replicate = 'proxy.replicate';
    case Reorder = 'proxy.reorder';
}
