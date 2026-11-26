<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Runs;

use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Runs\Tables\ProcessRunsTable;
use App\Models\Procurement\Process\ProcessRun;
use AzGuard\Filament\Concerns\AuthorizesResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Drilldown запусков Process-ядра: намерение запуска, completeness discovery и учёт required work.
 * Только чтение; CancelRun идёт через ProcessOperatorCommands с версией run и audit.
 */
final class ProcessRunResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $azguardKey = 'process_run';

    protected static ?string $model = ProcessRun::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Процессы: запуски';

    protected static ?string $modelLabel = 'запуск процессов';

    protected static ?string $pluralModelLabel = 'запуски процессов';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    public static function table(Table $table): Table
    {
        return ProcessRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListProcessRuns::route('/')];
    }
}
