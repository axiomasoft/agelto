<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\WorkWake;
use App\Services\Procurement\Process\QueueWorkWakePublisher;
use Illuminate\Container\Attributes\Bind;

/**
 * Внешний publish wake в Redis queue. Вызывается relay вне любой DB transaction;
 * исключение означает «не опубликовано» — сообщение остаётся в outbox. Повторная публикация того же
 * wake безопасна: stale/duplicate delivery отвергается claim по dispatch generation.
 */
#[Bind(QueueWorkWakePublisher::class)]
interface WorkWakePublisher
{
    public function publish(WorkWake $wake): void;
}
