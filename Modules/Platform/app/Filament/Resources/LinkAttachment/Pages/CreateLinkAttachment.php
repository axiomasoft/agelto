<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\LinkAttachment\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\LinkAttachment\LinkAttachmentResource;

final class CreateLinkAttachment extends CreateRecord
{
    protected static string $resource = LinkAttachmentResource::class;
}
