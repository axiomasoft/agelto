<?php

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\CreateDatasetExample;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\EditDatasetExample;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ListDatasetExamples;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Schemas\DatasetExampleForm;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Tables\DatasetExamplesTable;
use App\Models\Procurement\Ai\DatasetExample;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DatasetExampleResource extends Resource
{
    protected static ?string $model = DatasetExample::class;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DatasetExampleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DatasetExamplesTable::configure($table);
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
            'create' => CreateDatasetExample::route('/create'),
            'edit' => EditDatasetExample::route('/{record}/edit'),
        ];
    }
}
