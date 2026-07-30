<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Services\Llm\LlmProviderFactory;

/**
 * Строка `llm_providers.driver` не резолвится ни одним провайдером —
 * {@see LlmProviderFactory::forDriver()} (D110).
 */
final class UnknownProviderDriver extends OperationFailed
{
    public function __construct(string $driver)
    {
        parent::__construct("Неизвестный драйвер провайдера: {$driver}.");
    }
}
