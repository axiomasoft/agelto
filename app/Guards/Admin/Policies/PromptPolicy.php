<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\PromptPermission;
use App\Models\Llm\Prompt;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

/**
 * Защищённые канонические промты (`is_protected=true`) доступны в админке только для чтения.
 * Правка и удаление разрешены для операторских записей; `PromptPermission` остаётся предусловием.
 *
 * `PromptResource` исключён из enum-RBAC в `config/az-guard-filament.php`, поэтому политика
 * явно закрывает все Filament abilities: viewAny/view/create/update/delete/restore/
 * forceDelete/replicate/reorder. Непокрытая ability иначе могла бы разрешиться без проверки права.
 */
#[GuardPolicy(model: Prompt::class)]
final class PromptPolicy
{
    #[GateAbility(permission: PromptPermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::ViewAny);
    }

    #[GateAbility(permission: PromptPermission::View)]
    public function view(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::View);
    }

    #[GateAbility(permission: PromptPermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Create);
    }

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

    // Абилки *Any (bulk/коллекционные) переиспользуют permission своей record-level
    // пары (deleteAny → Delete, restoreAny → Restore, forceDeleteAny → ForceDelete) —
    // тот же slug, что и в ResourceGate/FilamentActions::MAP. Без #[GateAbility]:
    // атрибут регистрирует permission-строку как отдельную RAW Gate-абилку
    // (`PolicyAttributeRegistrar`) РОВНО ОДИН раз панель-wide — повторная декорация
    // того же permission на второй метод падает на boot ("ability already registered").
    // Метод всё равно нужен: Filament резолвит абилки по имени метода политики
    // (`method_exists`), а не по атрибуту.
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Delete);
    }

    #[GateAbility(permission: PromptPermission::Restore)]
    public function restore(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::Restore) && ! $prompt->is_protected;
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Restore);
    }

    #[GateAbility(permission: PromptPermission::ForceDelete)]
    public function forceDelete(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::ForceDelete) && ! $prompt->is_protected;
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(PromptPermission::ForceDelete);
    }

    #[GateAbility(permission: PromptPermission::Replicate)]
    public function replicate(User $user, Prompt $prompt): bool
    {
        return $user->hasPermission(PromptPermission::Replicate) && ! $prompt->is_protected;
    }

    #[GateAbility(permission: PromptPermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(PromptPermission::Reorder);
    }
}
