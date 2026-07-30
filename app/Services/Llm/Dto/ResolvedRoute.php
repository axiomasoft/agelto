<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use App\Models\Llm\LlmRoute;
use App\Services\Llm\Contracts\LlmProvider;

/**
 * Результат резолва строки `llm_routes` (`LlmRouteResolver`, ТЗ 04 §3.1): готовый к вызову
 * `LlmProvider` + собранные `LlmOptions` (provider/model — строковые идентификаторы, не
 * Eloquent-объекты, P3.1 Known Deviations) + исходная модель маршрута — нужна вызывающему коду
 * (`ReaskPolicy`, P3.4) для эскалации на следующий по `priority` маршрут при провале.
 */
final readonly class ResolvedRoute
{
    public function __construct(
        public LlmProvider $provider,
        public LlmOptions $options,
        public LlmRoute $route,
    ) {}
}
