<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Incidents;

use App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Procurement\Process\Incidents\Tables\IncidentsTable;
use App\Models\Procurement\Process\Incident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Инциденты Process-ядра (handler hold, исчерпанный fault budget и т.п.). Только чтение;
 * «Принято» идёт через ProcessOperatorCommands и пишет audit transition.
 */
final class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Процессы: инциденты';

    protected static ?string $modelLabel = 'инцидент процессов';

    protected static ?string $pluralModelLabel = 'инциденты процессов';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListIncidents::route('/')];
    }
}
