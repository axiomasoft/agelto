<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Service\Pages;

use Filament\Resources\Pages\CreateRecord;
use Sys\Filament\Resources\Service\ServiceResource;

final class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;
}
