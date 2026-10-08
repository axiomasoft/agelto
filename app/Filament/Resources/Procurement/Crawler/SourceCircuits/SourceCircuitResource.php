<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits;

use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\EditSourceCircuit;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\ListSourceCircuits;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Schemas\SourceCircuitForm;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Tables\SourceCircuitsTable;
use App\Models\Procurement\Crawler\SourceCircuit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * «Источники»: состояние брейкера каждой площадки и ручное управление обходом —
 * пауза, «возобновить сейчас» (минуя ожидание), пороги брейкера и интервал автоповтора.
 * Строки создаёт автоматика (`SourceCircuitRepository::forStage`), поэтому create/delete нет.
 */
final class SourceCircuitResource extends Resource
{
    protected static ?string $model = SourceCircuit::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Источники: состояние';

    protected static ?string $modelLabel = 'источник';

    protected static ?string $pluralModelLabel = 'источники';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function form(Schema $schema): Schema
    {
        return SourceCircuitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourceCircuitsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSourceCircuits::route('/'),
            'edit' => EditSourceCircuit::route('/{record}/edit'),
        ];
    }
}
