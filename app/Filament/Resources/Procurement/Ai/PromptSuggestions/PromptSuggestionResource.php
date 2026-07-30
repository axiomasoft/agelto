<?php

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions;

use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ListPromptSuggestions;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ViewPromptSuggestion;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Schemas\PromptSuggestionInfolist;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Tables\PromptSuggestionsTable;
use App\Models\Procurement\Ai\PromptSuggestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Очередь предложений критика по текстам системных инструкций (P5.6, D47) — read-only реестр
 * (index + view): записи создаёт `estimate:prompts:suggest`, человек только РЕШАЕТ, а файл промта
 * пишет `ai:prompts:apply`. Create/edit-страниц нет намеренно — тот же канон, что
 * `AnalysisResource`: экран, умеющий править предложение, стал бы вторым редактором канона.
 */
class PromptSuggestionResource extends Resource
{
    protected static ?string $model = PromptSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    // Сразу после PromptResource (30): очередь — про те же промты.
    protected static ?int $navigationSort = 35;

    protected static ?string $modelLabel = 'предложение по промту';

    protected static ?string $pluralModelLabel = 'предложения по промтам';

    public static function infolist(Schema $schema): Schema
    {
        return PromptSuggestionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptSuggestionsTable::configure($table);
    }

    // Eager-load автора решения — он показан и в списке, и в блоке аудита карточки (против N+1).
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['decidedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptSuggestions::route('/'),
            'view' => ViewPromptSuggestion::route('/{record}'),
        ];
    }

    /**
     * Происхождение предложения одной читаемой строкой — общий для списка и карточки: критик
     * привязывает запись к одной-двум точкам контура, а не ко всем четырём сразу, и четыре
     * столбца-пустышки читались бы хуже одной строки.
     */
    public static function origin(PromptSuggestion $suggestion): string
    {
        $parts = [];

        foreach ([
            'анализ' => $suggestion->analysis_id,
            'эталон' => $suggestion->fixture_id,
            'прогон' => $suggestion->eval_run_id,
            'вызов' => $suggestion->llm_call_id,
        ] as $label => $id) {
            if ($id !== null) {
                $parts[] = "{$label} #{$id}";
            }
        }

        return implode(', ', $parts);
    }
}
