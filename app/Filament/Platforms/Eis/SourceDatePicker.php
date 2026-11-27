<?php

declare(strict_types=1);

namespace App\Filament\Platforms\Eis;

use Filament\Forms\Components\DatePicker;

final class SourceDatePicker extends DatePicker
{
    protected function getStateCastTimezone(): ?string
    {
        // A date-only value is a calendar day at the source, not midnight in app.timezone.
        return $this->getTimezone();
    }
}
