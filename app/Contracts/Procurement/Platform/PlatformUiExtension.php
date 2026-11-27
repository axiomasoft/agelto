<?php

declare(strict_types=1);

namespace App\Contracts\Procurement\Platform;

use App\Models\Procurement\Procurement;
use Filament\Schemas\Components\Component;

interface PlatformUiExtension
{
    /** @return list<Component> */
    public function profileFormSchema(): array;

    /** @return list<Component> */
    public function procurementInfolistSections(Procurement $procurement): array;
}
