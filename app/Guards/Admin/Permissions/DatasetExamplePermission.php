<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Ai\DatasetExample;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: DatasetExample::class)]
enum DatasetExamplePermission: string
{
    case ViewAny = 'dataset_example.view_any';
    case View = 'dataset_example.view';
    case Create = 'dataset_example.create';
    case Update = 'dataset_example.update';
    case Delete = 'dataset_example.delete';
    case Restore = 'dataset_example.restore';
    case ForceDelete = 'dataset_example.force_delete';
    case Replicate = 'dataset_example.replicate';
    case Reorder = 'dataset_example.reorder';
}
