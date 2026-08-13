<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\OperationLogs;

use App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs;
use App\Filament\Resources\Procurement\OperationLogs\Tables\OperationLogsTable;
use App\Models\Procurement\OperationLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class OperationLogResource extends Resource
{
    protected static ?string $model = OperationLog::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Журнал процессов';

    protected static ?string $modelLabel = 'запись журнала процессов';

    protected static ?string $pluralModelLabel = 'журнал процессов';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function table(Table $table): Table
    {
        return OperationLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListOperationLogs::route('/')];
    }
}
