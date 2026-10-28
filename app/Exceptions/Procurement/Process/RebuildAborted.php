<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

use App\Enums\Procurement\Process\RebuildStatus;

/**
 * Управляющий выход rebuild: бросается внутри snapshot tx, чтобы откатить её целиком, и
 * превращается в отказ RebuildResult. Не programming fault и не попадает в handler/daemon fault.
 */
final class RebuildAborted extends \RuntimeException
{
    public function __construct(public readonly RebuildStatus $status)
    {
        parent::__construct('Projection rebuild aborted: '.$status->value);
    }
}
