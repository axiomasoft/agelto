<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Prompts;

use App\Filament\Resources\Procurement\Ai\Prompts\Pages\CreatePrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\EditPrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ListPrompts;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ViewPrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\Schemas\PromptForm;
use App\Filament\Resources\Procurement\Ai\Prompts\Schemas\PromptInfolist;
use App\Filament\Resources\Procurement\Ai\Prompts\Tables\PromptsTable;
use App\Models\Llm\Prompt;
use AzGuard\Filament\Concerns\AuthorizesResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

class PromptResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $azguardKey = 'prompt';

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    protected static ?string $model = Prompt::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройка AI';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Инструкции для AI';

    protected static ?string $modelLabel = 'инструкция для AI';

    protected static ?string $pluralModelLabel = 'инструкции для AI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromptInfolist::configure($schema);
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
            'view' => ViewPrompt::route('/{record}'),
            'edit' => EditPrompt::route('/{record}/edit'),
        ];
    }
}
