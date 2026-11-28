<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Console\Concerns\RendersReport;

/**
 * Флаг `--format` команды сервисного слоя вне `json|md` —
 * {@see RendersReport::guardFormat()}.
 */
final class UnknownReportFormat extends OperationFailed
{
    public function __construct(string $format)
    {
        parent::__construct("Неизвестный --format={$format} (ожидается json|md).");
    }
}
