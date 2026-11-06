<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\StatusMappings;

use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\CreateStatusMapping;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\EditStatusMapping;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\ListStatusMappings;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Schemas\StatusMappingForm;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Tables\StatusMappingsTable;
use App\Guards\Admin\Permissions\StatusMappingPermission;
use App\Models\Procurement\Platform\StatusMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class StatusMappingResource extends Resource
{
    protected static ?string $model = StatusMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'source_stage';

    protected static string|\UnitEnum|null $navigationGroup = 'Площадки';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Соответствие статусов';

    protected static ?string $modelLabel = 'соответствие статуса';

    protected static ?string $pluralModelLabel = 'соответствие статусов';

    public static function form(Schema $schema): Schema
    {
        return StatusMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StatusMappingsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermission(permission: StatusMappingPermission::Create, panelId: 'admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatusMappings::route('/'),
            'create' => CreateStatusMapping::route('/create'),
            'edit' => EditStatusMapping::route('/{record}/edit'),
        ];
    }
}
