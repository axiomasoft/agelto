<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\Related;

use App\Models\Procurement\ProcurementEvent;
use AzGuard\Filament\Concerns\AuthorizesResource;
use Filament\Resources\Resource;

final class ProcurementEventResource extends Resource
{
    use AuthorizesResource;

    protected static bool $isDiscovered = false;

    protected static ?string $azguardKey = 'procurement';

    protected static ?string $model = ProcurementEvent::class;
}
