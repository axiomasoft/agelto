<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Providers\Pages;

use App\Filament\Resources\Procurement\Ai\Providers\ProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProvider extends CreateRecord
{
    protected static string $resource = ProviderResource::class;
}
