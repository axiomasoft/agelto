<?php

declare(strict_types=1);

namespace App\Guards\Admin\Roles;

use App\Guards\Admin\Permissions\AiRoutePermission;
use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Guards\Admin\Permissions\CrawlerPermission;
use App\Guards\Admin\Permissions\DatasetExamplePermission;
use App\Guards\Admin\Permissions\EstimatePermission;
use App\Guards\Admin\Permissions\FixturePermission;
use App\Guards\Admin\Permissions\PlatformCheckPermission;
use App\Guards\Admin\Permissions\PlatformPermission;
use App\Guards\Admin\Permissions\PromptPermission;
use App\Guards\Admin\Permissions\PromptSuggestionPermission;
use App\Guards\Admin\Permissions\ProviderPermission;
use App\Guards\Admin\Permissions\ProxyPermission;
use App\Guards\Admin\Permissions\RegistryPermission;
use App\Guards\Admin\Permissions\RunPermission;
use App\Guards\Admin\Permissions\StatusMappingPermission;
use AzGuard\Roles\BaseRole;
use UnitEnum;

/**
 * Оператор: полный CRUD над операторской конфигурацией (площадки/реестры,
 * краулеры/прокси, AI-провайдеры/роутинг/промпты/датасет).
 * Управление прогонами (Run.update) и доступ к ролям/грантам НЕ входят.
 */
final class OperatorRole extends BaseRole
{
    public function getLevel(): int
    {
        return 100;
    }

    /** @return list<UnitEnum> */
    public function permissions(): array
    {
        return [
            ...$this->crud(PlatformPermission::class),
            ...$this->crud(RegistryPermission::class),
            ...$this->crud(CrawlerPermission::class),
            ...$this->crud(ProxyPermission::class),
            ...$this->crud(ProviderPermission::class),
            ...$this->crud(AiRoutePermission::class),
            ...$this->crud(PromptPermission::class),
            ...$this->crud(DatasetExamplePermission::class),
            // Прогоны: наблюдение + управление (возобновить/сброс), без create/delete.
            RunPermission::ViewAny,
            RunPermission::View,
            RunPermission::Update,
            // AI-анализы (P9.2): операторский цикл (проверено/коррекция/re-run) — наблюдение +
            // Update (действия карточки авторизуются через `update`, ResourceGate-конвенция),
            // без delete (пайплайн не удаляет анализы сам). Create (P5.2, D44) — право ЗАПУСКА
            // прогона с карточки эталона, проверяется строкой ability, не абилкой ресурса анализов.
            AnalysisPermission::ViewAny,
            AnalysisPermission::View,
            AnalysisPermission::Create,
            AnalysisPermission::Update,
            // Реестр эталонов обучающего контура (P5.1, D132/D133): загрузка и правка карточки,
            // без Delete (эталон удаляется вместе с записью манифеста — консольная операция).
            FixturePermission::ViewAny,
            FixturePermission::View,
            FixturePermission::Create,
            FixturePermission::Update,
            // Очередь предложений по промтам (P5.6, D47): только ЧТЕНИЕ. Update — это апрув
            // патча, меняющего поведение модели на всех документах; решение о тексте системной
            // инструкции остаётся владельческим (суперадмин), оператору не выдаётся.
            PromptSuggestionPermission::ViewAny,
            PromptSuggestionPermission::View,
            // Витрина смет с пометками неопределённости (P8.5, D137): read-only ресурс,
            // create/edit/delete-страниц у него нет — грант ограничен просмотром.
            EstimatePermission::ViewAny,
            EstimatePermission::View,
            // История healthcheck (P5.6): только чтение — витрина не меняет ни ожидания, ни факт.
            PlatformCheckPermission::ViewAny,
            PlatformCheckPermission::View,
            // Матрица статусов ЕИС: оператор читает пары и исправляет только канонический исход;
            // новые натуральные ключи создаёт исключительно StatusMappingService::resolve().
            StatusMappingPermission::ViewAny,
            StatusMappingPermission::View,
            StatusMappingPermission::Update,
        ];
    }

    /**
     * Стандартный CRUD-набор одного ресурса (без restore/forceDelete/replicate/reorder).
     *
     * @param  class-string<UnitEnum>  $enum
     * @return list<UnitEnum>
     */
    private function crud(string $enum): array
    {
        /** @var list<UnitEnum> $permissions */
        $permissions = [
            $enum::ViewAny,
            $enum::View,
            $enum::Create,
            $enum::Update,
            $enum::Delete,
        ];

        return $permissions;
    }
}
