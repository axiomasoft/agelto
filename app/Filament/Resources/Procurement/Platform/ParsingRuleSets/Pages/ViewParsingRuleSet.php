<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Exceptions\Procurement\Parsing\InvalidParsingRuleSetException;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use JsonException;

class ViewParsingRuleSet extends ViewRecord
{
    protected static string $resource = ParsingRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->validateJsonAction(),
            $this->createDraftAction(),
            $this->updateModeAction(),
        ];
    }

    private function validateJsonAction(): Action
    {
        return Action::make('validate_json')
            ->label('Проверить JSON')
            ->color('gray')
            ->authorize(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::View) ?? false)
            ->visible(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::View) ?? false)
            ->form([
                Textarea::make('rules_json')
                    ->label('JSON правил для проверки')
                    ->default(static function (ParsingRuleSet $record): string {
                        $active = $record->activeVersion;
                        if ($active === null) {
                            return '{}';
                        }
                        $encoded = json_encode($active->rules_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        return is_string($encoded) ? $encoded : '{}';
                    })
                    ->required()
                    ->rows(14),
            ])
            ->action(function (ParsingRuleSet $record, array $data): void {
                $rawJson = is_string($data['rules_json'] ?? null) ? $data['rules_json'] : '';

                try {
                    $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    Notification::make()->title('Ошибка JSON: '.$e->getMessage())->danger()->send();

                    return;
                }

                if (! is_array($decoded)) {
                    Notification::make()->title('Правила должны быть JSON-объектом.')->danger()->send();

                    return;
                }

                /** @var array<string, mixed> $decoded */
                $result = app(ParsingRuleManager::class)->validate(
                    stage: $record->stage,
                    rules: $decoded,
                    platformId: $record->platform_id,
                );

                if ($result->valid) {
                    Notification::make()
                        ->title(sprintf('JSON валиден (%s v%d)', $result->schemaId, $result->schemaVersion))
                        ->success()
                        ->send();
                } else {
                    $messages = array_map(
                        static fn ($issue): string => sprintf('[%s] %s', $issue->path, $issue->message),
                        array_slice($result->issues, 0, 5),
                    );
                    Notification::make()
                        ->title('Ошибки валидации схемы')
                        ->body(implode("\n", $messages))
                        ->danger()
                        ->send();
                }
            });
    }

    private function createDraftAction(): Action
    {
        return Action::make('create_draft')
            ->label('Создать версию (черновик)')
            ->color('primary')
            ->authorize(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Create) ?? false)
            ->visible(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Create) ?? false)
            ->form([
                TextInput::make('change_summary')
                    ->label('Описание изменений')
                    ->required()
                    ->maxLength(255),
                Textarea::make('rules_json')
                    ->label('JSON новой версии')
                    ->default(static function (ParsingRuleSet $record): string {
                        $active = $record->activeVersion;
                        if ($active === null) {
                            return '{}';
                        }
                        $encoded = json_encode($active->rules_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        return is_string($encoded) ? $encoded : '{}';
                    })
                    ->required()
                    ->rows(14),
            ])
            ->action(function (ParsingRuleSet $record, array $data): void {
                if (! (Auth::user()?->hasPermission(ParsingRuleSetPermission::Create) ?? false)) {
                    abort(403);
                }

                $rawJson = is_string($data['rules_json'] ?? null) ? $data['rules_json'] : '';

                try {
                    $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    Notification::make()->title('Ошибка JSON: '.$e->getMessage())->danger()->send();

                    return;
                }

                if (! is_array($decoded)) {
                    Notification::make()->title('Правила должны быть JSON-объектом.')->danger()->send();

                    return;
                }

                /** @var array<string, mixed> $decoded */
                $userId = Auth::id();
                $actorId = is_int($userId) ? $userId : null;

                $changeSummary = is_string($data['change_summary'] ?? null) ? $data['change_summary'] : 'Черновик через UI';

                try {
                    $version = app(ParsingRuleManager::class)->createDraft(
                        platformId: $record->platform_id,
                        stage: $record->stage,
                        rules: $decoded,
                        changeSummary: $changeSummary,
                        registryCode: $record->registry_code,
                        scopeKey: $record->scope_key,
                        source: ParsingRuleSource::Manual,
                        createdBy: $actorId,
                        actorType: ParsingRuleActorType::User,
                        actorId: $actorId,
                        autoHealMode: $record->auto_heal_mode,
                    );

                    Notification::make()
                        ->title(sprintf('Создан черновик версии v%d', $version->version))
                        ->success()
                        ->send();
                } catch (InvalidParsingRuleSetException $e) {
                    Notification::make()
                        ->title('Не удалось создать черновик')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function updateModeAction(): Action
    {
        return Action::make('update_mode')
            ->label('Изменить режим / Вкл-Выкл')
            ->color('warning')
            ->authorize(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Update) ?? false)
            ->visible(static fn (): bool => Auth::user()?->hasPermission(ParsingRuleSetPermission::Update) ?? false)
            ->form([
                Select::make('auto_heal_mode')
                    ->label('Режим самокоррекции')
                    ->options([
                        AutoHealMode::Disabled->value => 'Отключена (disabled)',
                        AutoHealMode::Propose->value => 'Предлагать (propose)',
                        AutoHealMode::AutoPromoteFallback->value => 'Авто-продвижение fallback (auto_promote_fallback)',
                    ])
                    ->default(static fn (ParsingRuleSet $record): string => $record->auto_heal_mode->value)
                    ->required(),
                Toggle::make('is_enabled')
                    ->label('Набор правил включён')
                    ->default(static fn (ParsingRuleSet $record): bool => $record->is_enabled),
                TextInput::make('reason')
                    ->label('Причина изменения')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (ParsingRuleSet $record, array $data): void {
                if (! (Auth::user()?->hasPermission(ParsingRuleSetPermission::Update) ?? false)) {
                    abort(403);
                }

                $store = app(ParsingRuleStoreRepository::class);
                $userId = Auth::id();
                $actorId = is_int($userId) ? $userId : null;
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : 'Изменение параметров через админ-панель';
                $modeRaw = is_string($data['auto_heal_mode'] ?? null) ? $data['auto_heal_mode'] : $record->auto_heal_mode->value;
                $newMode = AutoHealMode::from($modeRaw);
                $newEnabled = (bool) ($data['is_enabled'] ?? true);

                if ($newMode !== $record->auto_heal_mode) {
                    $store->setAutoHealMode(
                        ruleSetId: $record->id,
                        mode: $newMode,
                        actorType: ParsingRuleActorType::User,
                        actorId: $actorId,
                        reason: $reason,
                    );
                }

                if ($newEnabled !== $record->is_enabled) {
                    $store->setEnabled(
                        ruleSetId: $record->id,
                        enabled: $newEnabled,
                        actorType: ParsingRuleActorType::User,
                        actorId: $actorId,
                        reason: $reason,
                    );
                }

                Notification::make()
                    ->title('Настройки набора правил обновлены')
                    ->success()
                    ->send();
            });
    }
}
