<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\Related;

use App\Models\Procurement\Estimate\Item;
use AzGuard\Filament\Concerns\AuthorizesResource;
use Filament\Resources\Resource;

final class EstimateItemResource extends Resource
{
    use AuthorizesResource;

    protected static bool $isDiscovered = false;

    protected static ?string $azguardKey = 'estimate';

    protected static ?string $model = Item::class;
}
