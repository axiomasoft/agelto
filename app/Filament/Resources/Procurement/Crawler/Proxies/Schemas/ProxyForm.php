<?php

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Schemas;

use App\Enums\Procurement\Proxy\ProxyScope;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;

class ProxyForm
{
    /**
     * Коды стран, которыми сегодня реально размечены прокси-провайдеры проекта (ISO 3166-1
     * alpha-2) — закрытый список вместо свободного текста (research/02 §3.1: гейт P1.5 сравнивает
     * `country` буквально, опечатка в свободном поле обходит гейт запрещённых стран).
     *
     * Публичный — переиспользуется `CrawlerForm` для поля `proxy_country` (то же требование
     * закрытого списка, тот же источник факта).
     *
     * @var array<string, string>
     */
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
        return $schema
            ->components([
                TextInput::make('service')
                    ->required(),
                TextInput::make('host')
                    ->required(),
                TextInput::make('port')
                    ->numeric(),
                TextInput::make('username'),
                // Креды прокси маскируются (admin-panel §2.1): скрытый ввод с
                // возможностью показать; пустое значение при правке не перетирает пароль.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('country')
                    ->options(self::COUNTRIES)
                    ->searchable(),
                Select::make('scopes')
                    ->options(ProxyScope::class)
                    ->multiple()
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('fails_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                // Здоровье — рантайм-состояние автомата breaker'а (research/02 §7), не настройка
                // оператора: правка руками ломает автомат P1.4, поля только читаются.
                Fieldset::make('Здоровье (только чтение)')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('open_count')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('open_until')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_failure_reason')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_success_at')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_failure_at')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Repeater::make('targets')
                    ->label('Здоровье по хостам')
                    ->relationship()
                    ->disabled()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        TextInput::make('target')->label('Хост')->disabled(),
                        TextInput::make('consecutive_failures')->label('Серия отказов')->disabled(),
                        TextInput::make('open_until')->label('Закрыт до')->disabled(),
                        TextInput::make('last_failure_reason')->label('Причина')->disabled(),
                        TextInput::make('last_outcome_at')->label('Последний исход')->disabled(),
                    ]),
            ]);
    }
}
