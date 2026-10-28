<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Work;

use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Filament\Resources\Procurement\Process\Work\Tables\WorkItemsTable;
use App\Models\Procurement\Process\WorkItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Drilldown work Process-ядра: почему работа ждёт, когда следующая попытка, сколько попыток и
 * бюджет ошибок. Только чтение; CancelWork и RetryHandlerFaultedWork идут через
 * ProcessOperatorCommands с expected transition_seq и audit.
 */
final class WorkItemResource extends Resource
{
    protected static ?string $model = WorkItem::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 23;

    protected static ?string $navigationLabel = 'Процессы: работа';

    protected static ?string $modelLabel = 'работа процесса';

    protected static ?string $pluralModelLabel = 'работа процессов';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function table(Table $table): Table
    {
        return WorkItemsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListWorkItems::route('/')];
    }
}
