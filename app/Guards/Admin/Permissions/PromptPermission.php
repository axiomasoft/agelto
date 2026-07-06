<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum PromptPermission: string
{
    case ViewAny = 'prompt.view_any';
    case View = 'prompt.view';
    case Create = 'prompt.create';
    case Update = 'prompt.update';
    case Delete = 'prompt.delete';
    case Restore = 'prompt.restore';
    case ForceDelete = 'prompt.force_delete';
    case Replicate = 'prompt.replicate';
    case Reorder = 'prompt.reorder';
}
