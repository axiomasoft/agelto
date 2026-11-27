<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

/** Provenance link run→work: work принадлежит своему work_key, run лишь создал/обнаружил/наблюдает его. */
enum RunWorkRelation: string
{
    case Created = 'created';
    case Discovered = 'discovered';
    case Observes = 'observes';
}
