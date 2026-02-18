<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\DocumentAnalysis;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Platform\Filament\Resources\DocumentAnalysis\Schemas\DocumentAnalysisForm;
use Platform\Filament\Resources\DocumentAnalysis\Tables\DocumentAnalysesTable;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

final class DocumentAnalysisResource extends Resource
{
    protected static ?string $model = DocumentAnalysis::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Анализ документов';

    protected static ?string $breadcrumb = 'Анализ документов';

    protected static ?string $modelLabel = 'Анализ документа';

    protected static ?string $pluralLabel = 'Анализ документов';

    public static function form(Schema $schema): Schema
    {
        return DocumentAnalysisForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentAnalysesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentAnalyses::route('/'),
            'create' => Pages\CreateDocumentAnalysis::route('/create'),
            'edit' => Pages\EditDocumentAnalysis::route('/{record}/edit'),
        ];
    }
}
