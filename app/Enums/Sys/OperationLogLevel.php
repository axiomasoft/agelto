<?php

declare(strict_types=1);

namespace App\Enums\Sys;

/**
 * Уровень записи операционного журнала `sys_operation_logs`.
 */
enum OperationLogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
