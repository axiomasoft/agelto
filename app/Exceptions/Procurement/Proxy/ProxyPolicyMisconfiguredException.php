<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\OperationFailed;

/**
 * Пустая политика запрещённых стран для `llm` — ошибка конфигурации. Отдельный тип
 * исключения не перехватывается как `NoProxyAvailableException`, чтобы отказ политики
 * не разрешил env fallback без проверки страны.
 */
final class ProxyPolicyMisconfiguredException extends OperationFailed
{
    public function __construct(ProxyScope $scope)
    {
        parent::__construct("Политика запрещённых стран для скоупа «{$scope->value}» пуста — это неверная конфигурация, а не «запретов нет».");
    }
}
