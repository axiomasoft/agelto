<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Schemas;

use App\Enums\Procurement\Proxy\ProxyScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProxyForm
{
    public const array COUNTRIES = [
        'RU' => 'Россия',
        'NL' => 'Нидерланды',
        'DE' => 'Германия',
        'GB' => 'Великобритания',
        'FR' => 'Франция',
        'US' => 'США',
        'PL' => 'Польша',
        'TR' => 'Турция',
        'KZ' => 'Казахстан',
        'AE' => 'ОАЭ',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make('Параметры маршрута')->schema([
            TextInput::make('service')->label('Сервис')->required(),
            TextInput::make('host')->label('Хост')->required(),
            TextInput::make('port')->label('Порт')->integer()->minValue(1)->maxValue(65535)->required(),
            TextInput::make('username')->label('Имя пользователя'),
            TextInput::make('secret_ref')->label('Имя секрета')->regex('/^[A-Z][A-Z0-9_]{0,127}$/D')
                ->helperText('Имя переменной окружения или mounted secret с учётными данными узла.'),
            Select::make('country')->label('Страна')->options(self::COUNTRIES),
            Select::make('scopes')->label('Назначение запросов')->options(ProxyScope::class)->multiple(),
            Toggle::make('enabled')->label('Маршрут включён')->default(true),
            Textarea::make('reason')->label('Причина изменения')->required()->maxLength(200),
        ])->columns(2)->columnSpanFull()]);
    }
}
