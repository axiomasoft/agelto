<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

use App\Models\Procurement\Parsing\ParsingRuleSet;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[RequiresGrant]
#[Resource(model: ParsingRuleSet::class)]
enum ParsingRuleSetPermission: string
{
    case ViewAny = 'parsing_rule_set.view_any';
    case View = 'parsing_rule_set.view';
    case Create = 'parsing_rule_set.create';
    case Update = 'parsing_rule_set.update';
    case Activate = 'parsing_rule_set.activate';
    case Rollback = 'parsing_rule_set.rollback';
    case AutoHeal = 'parsing_rule_set.auto_heal';
}
