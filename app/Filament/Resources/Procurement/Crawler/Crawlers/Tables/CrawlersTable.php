<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Tables;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\User;
use App\Services\Procurement\Acquisition\LaunchAcquisition;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class CrawlersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Название')->searchable(),
            TextColumn::make('registry.name')->label('Реестр'),
            TextColumn::make('listing_kind')->label('Режим')->formatStateUsing(fn (mixed $state): string => $state instanceof \BackedEnum ? (string) $state->value : '—'),
            TextColumn::make('schedule')->label('Расписание (UTC)')->placeholder('—')->visibleFrom('md'),
            TextColumn::make('routeProfile.profile_key')->label('Профиль маршрутов')->placeholder(config()->string('outbound.routing.default_profile'))->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('is_active')->label('Активно')->boolean(),
        ])->recordActions([
            Action::make('start')->label('Запустить')->requiresConfirmation()
                ->visible(fn (Crawler $record): bool => $record->is_active && (Auth::user()?->hasPermission(ProcessControlPermission::LaunchRun, panelId: 'admin') ?? false))
                ->schema([
                    Hidden::make(OperatorActionInput::FIELD)->required()->default(fn (Crawler $record): string => OperatorActionInput::issue('launch_acquisition', (string) $record->id, $record->profile_version)),
                    Hidden::make('command_id')->required()->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (Action $action, Crawler $record, array $data): void {
                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);
                    $version = OperatorActionInput::confirmedVersion($action, 'launch_acquisition', (string) $record->id, $data);
                    try {
                        $commandId = $data['command_id'] ?? null;
                        if (! is_string($commandId)) {
                            throw new \InvalidArgumentException('Missing acquisition command identity.');
                        }
                        $id = app(LaunchAcquisition::class)->execute($actor, $record, $version, new ProcessUuid($commandId));
                    } catch (\InvalidArgumentException $failure) {
                        Notification::make()->title($failure->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->title('Запуск зарегистрирован')->body($id->value)->success()->send();
                }),
            Action::make('runs')->label('Прогоны')->url(ProcessRunResource::getUrl()),
            EditAction::make(),
        ]);
    }
}
