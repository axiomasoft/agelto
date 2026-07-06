<?php

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Pages;

use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProxies extends ListRecords
{
    protected static string $resource = ProxyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
