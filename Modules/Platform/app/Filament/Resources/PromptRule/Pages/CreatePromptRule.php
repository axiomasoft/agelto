<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\PromptRule\PromptRuleResource;

final class CreatePromptRule extends CreateRecord
{
    protected static string $resource = PromptRuleResource::class;
}
