<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Tables;

use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use App\Services\Procurement\Network\NetworkRouteCommands;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class ProxiesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service')->label('Сервис'),
            TextColumn::make('host')->label('Хост'),
            TextColumn::make('port')->label('Порт'),
            TextColumn::make('country')->label('Страна')->visibleFrom('md'),
            IconColumn::make('route.enabled')->label('Включён')->boolean(),
            TextColumn::make('route.health.state')->label('Здоровье маршрута'),
            TextColumn::make('route.health.reason_code')->label('Причина состояния'),
            TextColumn::make('route.disabled_reason')->label('Причина отключения')->wrap(),
        ])->recordActions([
            EditAction::make()->url(fn (Proxy $record): string => ProxyResource::getUrl('edit', ['record' => $record])),
            Action::make('reactivate')->label('Включить обратно')->authorize('update')
                ->schema([Textarea::make('reason')->label('Причина включения')->required()->maxLength(200)])
                ->visible(fn (Proxy $record): bool => $record->route?->enabled === false)
                ->action(function (Proxy $record, array $data): void {
                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);
                    app(NetworkRouteCommands::class)->reactivate($actor, $record, is_string($data['reason'] ?? null) ? $data['reason'] : '');
                }),
        ]);
    }
}
