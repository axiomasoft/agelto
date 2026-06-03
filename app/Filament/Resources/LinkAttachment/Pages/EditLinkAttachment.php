<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkAttachment\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\LinkAttachment\LinkAttachmentResource;

final class EditLinkAttachment extends EditRecord
{
    protected static string $resource = LinkAttachmentResource::class;

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['link_reg_number'] = $this->record->link?->reg_number;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
