<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkAttachment\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\LinkAttachment\LinkAttachmentResource;

final class CreateLinkAttachment extends CreateRecord
{
    protected static string $resource = LinkAttachmentResource::class;
}
