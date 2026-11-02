<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;

final class AnalysisNotActivated extends OperationFailed
{
    public function __construct()
    {
        parent::__construct('handler_not_activated: real analysis requires the pinned recipe and provider ledger cutover.');
    }
}
