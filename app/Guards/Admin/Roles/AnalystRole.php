<?php

declare(strict_types=1);

namespace App\Guards\Admin\Roles;

use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Guards\Admin\Permissions\FixturePermission;
use App\Guards\Admin\Permissions\ProcurementPermission;
use App\Guards\Admin\Permissions\RunPermission;
use AzGuard\Roles\BaseRole;
use UnitEnum;

/**
 * Аналитик: read-only на продуктовые реестры (закупки, прогоны, AI-анализы — P9.2, сдержанное
 * обещание докблока). Операторскую конфигурацию и управление доступом не видит. По мере
 * появления остальных ресурсов фазы 2 (сметы/классификаторы) сюда добавляются их view_any/view.
 */
final class AnalystRole extends BaseRole
{
    public function getLevel(): int
    {
        return 10;
    }

    /** @return list<UnitEnum> */
    public function permissions(): array
    {
        return [
            ProcurementPermission::ViewAny,
            ProcurementPermission::View,
            RunPermission::ViewAny,
            RunPermission::View,
            AnalysisPermission::ViewAny,
            AnalysisPermission::View,
            FixturePermission::ViewAny,
            FixturePermission::View,
        ];
    }
}
