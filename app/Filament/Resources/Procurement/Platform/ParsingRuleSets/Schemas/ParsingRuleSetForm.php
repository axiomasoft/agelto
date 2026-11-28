<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Schemas;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Models\Procurement\Platform\Platform;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ParsingRuleSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Координаты набора правил')
                    ->schema([
                        Select::make('platform_id')
                            ->label('Площадка')
                            ->options(static fn (): array => Platform::query()->orderBy('code')->pluck('code', 'id')->all())
                            ->required()
                            ->searchable(),
                        Select::make('stage')
                            ->label('Стадия')
                            ->options([
                                StageCode::Listing->value => StageCode::Listing->value,
                                StageCode::Detail->value => StageCode::Detail->value,
                                StageCode::StatusRefresh->value => StageCode::StatusRefresh->value,
                            ])
                            ->in([
                                StageCode::Listing->value,
                                StageCode::Detail->value,
                                StageCode::StatusRefresh->value,
                            ])
                            ->required(),
                        TextInput::make('registry_code')
                            ->label('Код реестра (необязательно)')
                            ->maxLength(64)
                            ->nullable(),
                        TextInput::make('scope_key')
                            ->label('Scope')
                            ->default('default')
                            ->required()
                            ->maxLength(64),
                        Select::make('auto_heal_mode')
                            ->label('Режим самокоррекции')
                            ->options([
                                AutoHealMode::Disabled->value => 'Отключена (disabled)',
                                AutoHealMode::Propose->value => 'Предлагать (propose)',
                                AutoHealMode::AutoPromoteFallback->value => 'Авто-продвижение fallback (auto_promote_fallback)',
                            ])
                            ->default(AutoHealMode::Propose->value)
                            ->visible(static fn (): bool => (Auth::user()?->hasPermission(ParsingRuleSetPermission::AutoHeal) ?? false)
                                || (Auth::user()?->hasPermission(ParsingRuleSetPermission::Update) ?? false))
                            ->required(),
                        TextInput::make('change_summary')
                            ->label('Описание начальной версии')
                            ->default('Первоначальный черновик через админ-панель')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('JSON-правила начального черновика')
                    ->schema([
                        Textarea::make('rules_json')
                            ->label('Правила (JSON)')
                            ->required()
                            ->rows(16)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
