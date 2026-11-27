<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\PromptPermission;
use App\Models\Llm\Prompt;
use App\Models\User;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;

/**
 * Защищённые канонические промты (`is_protected=true`) доступны в админке только для чтения.
 * Правка и удаление разрешены для операторских записей; `PromptPermission` остаётся предусловием.
 *
 * `PromptResource` исключён из enum-RBAC в `config/azguard-filament.php`, поэтому политика
 * явно закрывает все Filament abilities: viewAny/view/create/update/delete/restore/
 * forceDelete/replicate/reorder. Непокрытая ability иначе могла бы разрешиться без проверки права.
 */
#[PolicyFor(PromptPermission::class)]
final class PromptPolicy
{
    #[Decides(PromptPermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::ViewAny);
    }

    #[Decides(PromptPermission::View)]
    public function view(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::View);
    }

    #[Decides(PromptPermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Create);
    }

    #[Decides(PromptPermission::Update)]
    public function update(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::Update) && ($prompt === null || ! $prompt->is_protected);
    }

    #[Decides(PromptPermission::Delete)]
    public function delete(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::Delete) && ($prompt === null || ! $prompt->is_protected);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    #[Decides(PromptPermission::Restore)]
    public function restore(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::Restore) && ($prompt === null || ! $prompt->is_protected);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Restore);
    }

    #[Decides(PromptPermission::ForceDelete)]
    public function forceDelete(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::ForceDelete) && ($prompt === null || ! $prompt->is_protected);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::ForceDelete);
    }

    #[Decides(PromptPermission::Replicate)]
    public function replicate(User $user, ?Prompt $prompt = null): bool
    {
        return $user->hasPermission(PromptPermission::Replicate) && ($prompt === null || ! $prompt->is_protected);
    }

    #[Decides(PromptPermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Reorder);
    }
}
