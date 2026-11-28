<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\ControlScopes\Pages;

use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use Filament\Resources\Pages\ListRecords;

final class ListControlScopes extends ListRecords
{
    protected static string $resource = ControlScopeResource::class;
}
