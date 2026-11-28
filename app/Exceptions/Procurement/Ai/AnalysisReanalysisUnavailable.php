<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;

final class AnalysisReanalysisUnavailable extends OperationFailed
{
    public function __construct()
    {
        parent::__construct('Для этой работы нужен явный повтор на экране процессов. Прежний результат и учтённые запросы сохранены.');
    }
}
