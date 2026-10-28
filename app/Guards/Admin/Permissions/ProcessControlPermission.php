<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

/**
 * Typed-команды Process-ядра (observability#s04): каждое право соответствует одному operator action.
 * RetryHandlerFault намеренно не выдаётся OperatorRole — свежий budget для дефектного handler
 * остаётся решением суперадмина.
 */
enum ProcessControlPermission: string
{
    case ViewAny = 'process_control.view_any';
    case View = 'process_control.view';
    case PauseScope = 'process_control.pause_scope';
    case ResumeScope = 'process_control.resume_scope';
    case CancelWork = 'process_control.cancel_work';
    case CancelRun = 'process_control.cancel_run';
    case RetryHandlerFault = 'process_control.retry_handler_fault';
    case AcknowledgeIncident = 'process_control.acknowledge_incident';
}
