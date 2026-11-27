<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages;

use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListPromptSuggestions extends ListRecords
{
    protected static string $resource = PromptSuggestionResource::class;
}
