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
 * правка/удаление/etc. доступны только операторским (`is_protected=false`)
 * записям, коарс-право (`PromptPermission::*`) остаётся предусловием.
 *
 * Исключение `PromptResource` из enum-RBAC (`config/az-guard-filament.php`,
 * D44) снимает `ResourceGate`-короткое замыкание сразу для ВСЕХ 9
 * Filament-абилок модели (viewAny/view/create/update/delete/restore/
 * forceDelete/replicate/reorder), не только update/delete — Filament
 * дефолтит непокрытую абилку в allow (`get_authorization_response()`),
 * поэтому политика обязана явно закрыть весь набор, иначе аутентифицированный
 * пользователь без права видит/создаёт промты в обход `hasPermission()`.
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
