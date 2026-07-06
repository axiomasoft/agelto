<?php

namespace App\Filament\Resources\Procurement\Ai\Prompts;

use App\Filament\Resources\Procurement\Ai\Prompts\Pages\CreatePrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\EditPrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ListPrompts;
use App\Filament\Resources\Procurement\Ai\Prompts\Schemas\PromptForm;
use App\Filament\Resources\Procurement\Ai\Prompts\Tables\PromptsTable;
use App\Models\Procurement\Ai\Prompt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PromptResource extends Resource
{
    protected static ?string $model = Prompt::class;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrompts::route('/'),
            'create' => CreatePrompt::route('/create'),
            'edit' => EditPrompt::route('/{record}/edit'),
        ];
    }
}
