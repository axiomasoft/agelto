<?php

declare(strict_types=1);

namespace App\Exceptions\Llm;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Llm\LlmProviderFactory;

/**
 * Строка `llm_providers.driver` не резолвится ни одним провайдером —
 * {@see LlmProviderFactory::forDriver}.
 */
final class UnknownProviderDriver extends OperationFailed
{
    public function __construct(string $driver)
    {
        parent::__construct("Неизвестный драйвер провайдера: {$driver}.");
    }
}
