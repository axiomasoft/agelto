<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkAttachment\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\LinkAttachment\LinkAttachmentResource;

final class ListLinkAttachments extends ListRecords
{
    protected static string $resource = LinkAttachmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
