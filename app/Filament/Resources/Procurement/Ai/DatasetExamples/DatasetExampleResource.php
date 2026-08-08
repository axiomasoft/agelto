<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ListDatasetExamples;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ViewDatasetExample;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Schemas\DatasetExampleInfolist;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Tables\DatasetExamplesTable;
use App\Models\Procurement\Ai\DatasetExample;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DatasetExampleResource extends Resource
{
    protected static ?string $model = DatasetExample::class;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function table(Table $table): Table
    {
        return DatasetExamplesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DatasetExampleInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['fixture', 'analysis', 'documentVersion.document', 'verifiedBy']);
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
            'index' => ListDatasetExamples::route('/'),
            'view' => ViewDatasetExample::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
