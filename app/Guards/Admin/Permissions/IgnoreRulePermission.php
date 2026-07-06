<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum IgnoreRulePermission: string
{
    case ViewAny = 'ignore_rule.view_any';
    case View = 'ignore_rule.view';
    case Create = 'ignore_rule.create';
    case Update = 'ignore_rule.update';
    case Delete = 'ignore_rule.delete';
    case Restore = 'ignore_rule.restore';
    case ForceDelete = 'ignore_rule.force_delete';
    case Replicate = 'ignore_rule.replicate';
    case Reorder = 'ignore_rule.reorder';
}
