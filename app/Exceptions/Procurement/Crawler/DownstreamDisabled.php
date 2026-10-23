<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Exceptions\Procurement\OperationFailed;

final class DownstreamDisabled extends OperationFailed
{
    public function __construct()
    {
        parent::__construct('Последующие стадии отключены (CRAWLER_DOWNSTREAM_ENABLED).');
    }
}
