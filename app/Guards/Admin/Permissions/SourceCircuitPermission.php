<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum SourceCircuitPermission: string
{
    case ViewAny = 'source_circuit.view_any';
    case View = 'source_circuit.view';
    case Update = 'source_circuit.update';
}
