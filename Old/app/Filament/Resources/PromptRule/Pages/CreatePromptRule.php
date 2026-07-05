<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptRule\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\PromptRule\PromptRuleResource;

final class CreatePromptRule extends CreateRecord
{
    protected static string $resource = PromptRuleResource::class;
}
