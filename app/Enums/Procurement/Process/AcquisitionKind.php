<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Вид listing обхода из intent (не ProcessRun.kind): входит в scheduled trigger identity. */
enum AcquisitionKind: string
{
    case Fresh = 'fresh';
    case Archive = 'archive';
    case Delta = 'delta';
}
