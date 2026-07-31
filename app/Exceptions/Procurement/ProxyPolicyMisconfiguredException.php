<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Enums\Procurement\ProxyScope;

/**
 * Пустая политика запретов для скоупа `llm` — неверная конфигурация, а не «запретов нет»
 * (research/02 §8, D84): без гейта страны запрос к Gemini мог бы уйти через необгейченный
 * env-фолбэк. НЕ наследует форму, которую ловит `PoolOutboundProxy` (`NoProxyAvailableException`) —
 * иначе предохранитель сам стал бы тем тихим обходом, ради закрытия которого он заводится.
 */
final class ProxyPolicyMisconfiguredException extends OperationFailed
{
    public function __construct(ProxyScope $scope)
    {
        parent::__construct("Политика запрещённых стран для скоупа «{$scope->value}» пуста — это неверная конфигурация, а не «запретов нет».");
    }
}
