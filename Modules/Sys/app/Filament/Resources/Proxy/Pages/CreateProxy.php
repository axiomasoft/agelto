<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Proxy\Pages;

use Filament\Resources\Pages\CreateRecord;
use Sys\Filament\Resources\Proxy\ProxyResource;

final class CreateProxy extends CreateRecord
{
    protected static string $resource = ProxyResource::class;
}
