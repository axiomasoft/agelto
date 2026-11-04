<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\ControlScopes;

use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\ControlScopes\Tables\ControlScopesTable;
use App\Models\Procurement\Process\ControlScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Control authority Process-ядра: что приостановлено оператором и кем. Только чтение; Pause/Resume
 * идут через ProcessOperatorCommands с версией строки и audit.
 */
final class ControlScopeResource extends Resource
{
    protected static ?string $model = ControlScope::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Процессы: управление';

    protected static ?string $modelLabel = 'область управления процессами';

    protected static ?string $pluralModelLabel = 'области управления процессами';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;

    public static function table(Table $table): Table
    {
        return ControlScopesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListControlScopes::route('/')];
    }
}
