<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Enums\Procurement\Proxy\ProxyScope;

/**
 * Резолвер не нашёл ни одного кандидата для скоупа/цели (research/02 §10) — «тихого прямого
 * запроса не существует»: это ОТКАЗ, а не молчаливая деградация до прямого соединения (дыра 8).
 */
final class NoProxyAvailableException extends OperationFailed
{
    public function __construct(ProxyScope $scope, string $target, string $reason)
    {
        parent::__construct("Нет доступного прокси для скоупа «{$scope->value}», цель «{$target}»: {$reason}.");
    }
}
