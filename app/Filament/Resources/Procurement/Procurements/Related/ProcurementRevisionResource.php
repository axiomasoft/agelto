<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\Related;

use App\Models\Procurement\ProcurementRevision;
use AzGuard\Filament\Concerns\AuthorizesResource;
use Filament\Resources\Resource;

final class ProcurementRevisionResource extends Resource
{
    use AuthorizesResource;

    protected static bool $isDiscovered = false;

    protected static ?string $azguardKey = 'procurement';

    protected static ?string $model = ProcurementRevision::class;
}
