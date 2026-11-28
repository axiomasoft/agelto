<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Rules\CardRoutesRule;

/**
 * Контракт маршрутизации вкладок/эндпоинтов детальной карточки закупки.
 */
interface CardUrlRouter
{
    public function buildUrl(CardRoutesRule $routes, string $tab, string $baseUrl, string $regNumber): ?string;

    /**
     * @return list<string>
     */
    public function availableTabs(CardRoutesRule $routes): array;
}
