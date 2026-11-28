<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\RelationManagers;

use App\Filament\Concerns\AuthorizesReadOnlyRelation;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventsRelationManager extends RelationManager
{
    use AuthorizesReadOnlyRelation;

    protected static ?string $azguardResource = ParsingRuleSetResource::class;

    protected static string $relationship = 'events';

    protected static ?string $title = 'Журнал событий';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('occurred_at')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Когда')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Событие')
                    ->badge(),
                TextColumn::make('actor_type')
                    ->label('Инициатор')
                    ->badge()
                    ->visibleFrom('md'),
                TextColumn::make('ruleVersion.version')
                    ->label('Версия')
                    ->formatStateUsing(static fn (mixed $state): string => is_int($state) || is_string($state) ? 'v'.(string) $state : '—')
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(static fn (?string $state): ?string => $state),
            ]);
    }
}
