<?php

namespace App\Filament\Resources\Procurement\Platform\Platforms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->required(),
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('base_url')
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
                                TextInput::make('throttle.requestsPerMinutePerProxy')
                                    ->label('Добавка на прокси в минуту')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText('Пусто — использовать проверенный дефолт конфига.'),
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
                Section::make('Доступность')
                    ->description('Неактивная площадка не участвует в новых заданиях сбора.')
                    ->schema([
                        Toggle::make('is_active')
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
