<?php

declare(strict_types=1);

namespace App\Guards\Admin\Roles;

use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Guards\Admin\Permissions\EstimatePermission;
use App\Guards\Admin\Permissions\FixturePermission;
use App\Guards\Admin\Permissions\ProcessRunPermission;
use App\Guards\Admin\Permissions\ProcurementPermission;
use App\Guards\Admin\Permissions\PromptSuggestionPermission;
use AzGuard\Roles\BaseRole;
use UnitEnum;

/**
 * Аналитик читает продуктовые реестры закупок, прогонов, AI-анализов, эталонов и смет.
 * Права на операторскую конфигурацию и управление доступом ему не выдаются.
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
            ProcessRunPermission::ViewAny,
            ProcessRunPermission::View,
            AnalysisPermission::ViewAny,
            AnalysisPermission::View,
            FixturePermission::ViewAny,
            FixturePermission::View,
            // Очередь предложений по промтам: аналитик читает очередь, решает — владелец.
            PromptSuggestionPermission::ViewAny,
            PromptSuggestionPermission::View,
            // Сметы доступны аналитику для чтения вместе с пометками неопределённости.
            EstimatePermission::ViewAny,
            EstimatePermission::View,
        ];
    }
}
