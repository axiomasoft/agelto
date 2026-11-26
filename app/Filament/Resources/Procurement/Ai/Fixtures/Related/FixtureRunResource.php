<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Related;

use App\Models\Procurement\Ai\AnalysisRun;
use AzGuard\Filament\Concerns\AuthorizesResource;
use Filament\Resources\Resource;

final class FixtureRunResource extends Resource
{
    use AuthorizesResource;

    protected static bool $isDiscovered = false;

    protected static ?string $azguardKey = 'fixture';

    protected static ?string $model = AnalysisRun::class;
}
