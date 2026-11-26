<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Platforms\Schemas;

use App\Services\Procurement\Proxyma\SettingsSections;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class PlatformForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Площадка')
                    ->description('Код, название и базовый адрес определяют источник закупочных данных.')
                    ->schema([
                        TextInput::make('uuid')
                            ->label('UUID')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('code')
                            ->label('Код')
                            ->required(),
                        TextInput::make('name')
                            ->label('Название')
                            ->required(),
                        TextInput::make('base_url')
                            ->label('Базовый адрес')
                            ->url()
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Ограничения обхода')
                    ->description('Значения задают лимиты запросов и ожидания. Пустое значение наследует проверенный дефолт конфигурации.')
                    ->schema([
                        Fieldset::make('Настройки обхода')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('throttle.requestsPerMinuteBase')
                                    ->label('Запросов в минуту без прокси')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText('Пусто — использовать проверенный дефолт конфига.'),
                                TextEntry::make('origin_quota_explanation')
                                    ->hiddenLabel()
                                    ->state('Добавка на прокси устарела и не применяется. Origin quota общая для всех маршрутов; число прокси её не увеличивает.'),
                                TextInput::make('throttle.maxWaitSeconds')
                                    ->label('Максимальное ожидание слота, сек')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText('Пусто — использовать проверенный дефолт конфига.'),
                                ...self::stageFields('listing', 'Листинг'),
                                ...self::stageFields('detail', 'Карточка'),
                                ...self::stageFields('document', 'Документ'),
                            ]),
                    ]),
                Section::make('Proxyma')->schema(fn (?Model $record): array => app(SettingsSections::class)->forRecord($record))->columnSpanFull(),
                Section::make('Доступность')
                    ->description('Неактивная площадка не участвует в новых заданиях сбора.')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->required(),
                    ]),
            ]);
    }

    /** @return array<int, TextInput> */
    private static function stageFields(string $stage, string $label): array
    {
        return [
            TextInput::make("throttle.stages.{$stage}.minPauseMs")->label("{$label}: пауза от, мс")->numeric()->minValue(0),
            TextInput::make("throttle.stages.{$stage}.maxPauseMs")->label("{$label}: пауза до, мс")->numeric()->minValue(0),
            TextInput::make("throttle.stages.{$stage}.retries")->label("{$label}: попытки")->numeric()->minValue(0),
            TextInput::make("throttle.stages.{$stage}.retryDelayMs")->label("{$label}: задержка retry, мс")->numeric()->minValue(0),
            TextInput::make("throttle.stages.{$stage}.timeoutSeconds")->label("{$label}: timeout, сек")->numeric()->minValue(1),
        ];
    }
}
