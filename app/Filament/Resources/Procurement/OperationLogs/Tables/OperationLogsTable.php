<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\OperationLogs\Tables;

use App\Enums\Procurement\OperationLogLevel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OperationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([TextColumn::make('created_at')->dateTime()->sortable(), TextColumn::make('level')->badge(), TextColumn::make('stage'), TextColumn::make('entity_type'), TextColumn::make('entity_id'), TextColumn::make('message')->wrap()])
            ->filters([SelectFilter::make('level')->options(collect(OperationLogLevel::cases())->pluck('value', 'value')->all()), SelectFilter::make('stage')->options(['detail' => 'detail', 'document' => 'document', 'budget' => 'budget', 'proxy' => 'proxy']), SelectFilter::make('entity_type')->options(['procurement' => 'procurement', 'document' => 'document', 'proxy' => 'proxy'])])
            ->defaultSort('created_at', 'desc');
    }
}
