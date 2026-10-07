<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceIncidents\Tables;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentSeverity;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Crawler\SourceIncident;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class SourceIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Время')->dateTime()->sortable(),
            TextColumn::make('severity')->label('Важность')->badge(),
            TextColumn::make('type')->label('Событие')->badge(),
            TextColumn::make('failure_kind')->label('Класс отказа')->badge()->placeholder('—'),
            TextColumn::make('platform.name')->label('Площадка')->visibleFrom('md'),
            TextColumn::make('crawler_id')->label('Профиль')->placeholder('—'),
            TextColumn::make('run_id')->label('Прогон')->placeholder('—')->searchable(),
            TextColumn::make('http_status')->label('HTTP')->placeholder('—'),
            TextColumn::make('message')->label('Сообщение')->wrap()->lineClamp(3)->tooltip(fn (?string $state): ?string => $state),
            TextColumn::make('request_params')->label('Где (запрос)')->formatStateUsing(fn (mixed $state): string => is_array($state) ? (string) json_encode($state, JSON_UNESCAPED_UNICODE) : '—')->toggleable(),
            TextColumn::make('cursor')->label('Курсор')->formatStateUsing(fn (mixed $state): string => is_array($state) ? (string) json_encode($state, JSON_UNESCAPED_UNICODE) : '—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('fetch_id')->label('Fetch')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('actor')->label('Кто')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('acknowledged_at')->label('Разобрано')->dateTime()->placeholder('—'),
        ])
            ->filters([
                SelectFilter::make('type')->label('Событие')->options(SourceIncidentType::class),
                SelectFilter::make('severity')->label('Важность')->options(SourceIncidentSeverity::class),
                SelectFilter::make('failure_kind')->label('Класс отказа')->options(SourceFailureKind::class),
                TernaryFilter::make('acknowledged')
                    ->label('Разобрано')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('acknowledged_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('acknowledged_at'),
                    ),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label('Разобрано')
                    ->authorize('update')
                    ->visible(fn (SourceIncident $record): bool => $record->acknowledged_at === null)
                    ->action(function (SourceIncident $record): void {
                        $email = Auth::user()?->getAttribute('email');
                        $record->update([
                            'acknowledged_at' => now(),
                            'acknowledged_by' => is_string($email) ? $email : 'operator',
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
