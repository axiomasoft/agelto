<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Promt\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\Promt\PromtResource;

final class CreatePromt extends CreateRecord
{
    protected static string $resource = PromtResource::class;
}
