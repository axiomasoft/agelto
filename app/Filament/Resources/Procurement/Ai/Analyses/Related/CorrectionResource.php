<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses\Related;

use App\Models\Procurement\Ai\Correction;
use AzGuard\Filament\Concerns\AuthorizesResource;
use Filament\Resources\Resource;

final class CorrectionResource extends Resource
{
    use AuthorizesResource;

    protected static bool $isDiscovered = false;

    protected static ?string $azguardKey = 'analysis';

    protected static ?string $model = Correction::class;
}
