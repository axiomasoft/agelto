<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum ProviderOperationPurpose: string
{
    case Generation = 'generation';
    case Cache = 'cache';
    case File = 'file';
    case Lookup = 'lookup';
}
