<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Llm\Prompt;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: Prompt::class)]
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
