<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\RelationManagers;

use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class VersionsRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static ?string $azguardResource = ParsingRuleSetResource::class;

    protected static string $relationship = 'versions';

    protected static ?string $title = 'Версии правил';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->orderByDesc('version'))
            ->columns([
                TextColumn::make('version')
                    ->label('Версия')
                    ->formatStateUsing(static fn (int $state): string => 'v'.$state)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('source')
                    ->label('Источник')
                    ->badge(),
                TextColumn::make('change_summary')
                    ->label('Описание')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(static fn (?string $state): ?string => $state),
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->visibleFrom('md')
                    ->sortable(),
                TextColumn::make('activated_at')
                    ->label('Активирована')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Активировать')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Activate) ?? false)
                    ->visible(static fn (ParsingRuleVersion $record): bool => (Auth::user()?->hasPermission(ParsingRuleSetPermission::Activate) ?? false)
                        && $record->status === ParsingRuleVersionStatus::Validated)
                    ->form([
                        TextInput::make('reason')
                            ->label('Причина активации')
                            ->default('Активация через админ-панель')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (ParsingRuleVersion $record, array $data): void {
                        if (! (Auth::user()?->hasPermission(ParsingRuleSetPermission::Activate) ?? false)) {
                            abort(403);
                        }

                        $userId = Auth::id();
                        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : 'Активация через админ-панель';
                        app(ParsingRuleManager::class)->activate(
                            ruleSetId: $record->rule_set_id,
                            versionId: $record->id,
                            actorType: ParsingRuleActorType::User,
                            actorId: is_int($userId) ? $userId : null,
                            reason: $reason,
                        );

                        Notification::make()
                            ->title(sprintf('Версия v%d активирована', $record->version))
                            ->success()
                            ->send();
                    }),
                Action::make('rollback')
                    ->label('Откатить к этой версии')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Rollback) ?? false)
                    ->visible(static fn (ParsingRuleVersion $record): bool => (Auth::user()?->hasPermission(ParsingRuleSetPermission::Rollback) ?? false)
                        && in_array($record->status, [ParsingRuleVersionStatus::Superseded], true))
                    ->form([
                        TextInput::make('reason')
                            ->label('Причина отката')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (ParsingRuleVersion $record, array $data): void {
                        if (! (Auth::user()?->hasPermission(ParsingRuleSetPermission::Rollback) ?? false)) {
                            abort(403);
                        }

                        $userId = Auth::id();
                        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : 'Откат через админ-панель';
                        app(ParsingRuleManager::class)->rollback(
                            ruleSetId: $record->rule_set_id,
                            targetVersionId: $record->id,
                            actorType: ParsingRuleActorType::User,
                            actorId: is_int($userId) ? $userId : null,
                            reason: $reason,
                        );

                        Notification::make()
                            ->title(sprintf('Выполнен откат к версии v%d', $record->version))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
