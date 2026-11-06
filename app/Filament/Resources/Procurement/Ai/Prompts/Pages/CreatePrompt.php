<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Prompts\Pages;

use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrompt extends CreateRecord
{
    protected static string $resource = PromptResource::class;
}
