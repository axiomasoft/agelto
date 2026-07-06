<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum ProcurementPermission: string
{
    case ViewAny = 'procurement.view_any';
    case View = 'procurement.view';
    case Create = 'procurement.create';
    case Update = 'procurement.update';
    case Delete = 'procurement.delete';
    case Restore = 'procurement.restore';
    case ForceDelete = 'procurement.force_delete';
    case Replicate = 'procurement.replicate';
    case Reorder = 'procurement.reorder';
}
