<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\PromptPermission;
use App\Models\Procurement\Ai\Prompt;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

/**
 * Канонические (сидовые, `is_protected=true`) промты read-only в админке:
 * правка/удаление доступны только операторским (`is_protected=false`) записям,
 * коарс-право (`PromptPermission::Update/::Delete`) остаётся предусловием.
 */
#[GuardPolicy(model: Prompt::class)]
final class PromptPolicy
{
    #[GateAbility(permission: PromptPermission::Update)]
    public function update(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::Update) && ! $prompt->is_protected;
    }

    #[GateAbility(permission: PromptPermission::Delete)]
    public function delete(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::Delete) && ! $prompt->is_protected;
    }
}
