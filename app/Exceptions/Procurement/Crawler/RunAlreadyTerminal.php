<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Crawler\Run;

/**
 * `RequestRunStopAction` (P3.4) отказывает на прогоне, уже завершившемся терминально
 * (`completed`/`failed`/`cancelled`) — «Остановить»/«Отменить» осмысленны только у
 * `running`/`paused` (`RunStatus::isTerminal()`), запрос остановки к тому моменту уже нечего
 * читать.
 */
final class RunAlreadyTerminal extends OperationFailed
{
    public static function forRun(Run $run): self
    {
        return new self(sprintf(
            'Прогон #%d уже завершён (статус «%s») — останавливать нечего.',
            $run->id,
            $run->status->value,
        ));
    }

    private function __construct(string $message)
    {
        parent::__construct($message);
    }
}
