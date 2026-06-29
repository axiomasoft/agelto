<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrawlerRun;

use App\Filament\Resources\CrawlerRun\Tables\CrawlerRunsTable;
use App\Models\Crawler\CrawlerRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * Read-only мониторинг прогонов краулера: статус, heartbeat, курсор, ошибки + журнал прогона.
 * Операторских действий нет (по решению заказчика) — только просмотр.
 */
final class CrawlerRunResource extends Resource
{
    protected static ?string $model = CrawlerRun::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Прогоны краулеров';

    protected static ?string $breadcrumb = 'Прогоны';

    protected static ?string $modelLabel = 'Прогон';

    protected static ?string $pluralLabel = 'Прогоны краулеров';

    public static function table(Table $table): Table
    {
        return CrawlerRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrawlerRuns::route('/'),
            'view' => Pages\ViewCrawlerRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
