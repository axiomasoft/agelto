<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Contracts\Procurement\Crawler\CrawlerPlatformForm;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\PlatformAdapterRegistry;
use Cron\CronExpression;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

final class CrawlerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Задание сбора')
                ->description('Реестр и режим определяют, что и как будет собирать задание.')
                ->schema([
                    Select::make('registry_id')->label('Реестр')->relationship('registry', 'name')->live()->required(),
                    Textarea::make('name')->label('Название')->required()->columnSpanFull(),
                    Select::make('mode')->label('Режим')->options(CrawlerMode::class)->required(),
                    Select::make('execution_lane')
                        ->label('Контур исполнения')
                        ->options([
                            CrawlerExecutionLane::Standard->value => 'Стандартный — до 5 минут',
                            CrawlerExecutionLane::Long->value => 'Длинный архивный — до 1 часа',
                        ])
                        ->default(CrawlerExecutionLane::Standard->value)
                        ->required(),
                    Select::make('stop_policy')->label('Режим работы')->options(RunStopPolicy::class)->required(),
                    Select::make('source_method')->label('Основной способ получения')->options(SourceMethod::class)->required(),
                    Select::make('fallback_method')->label('Резервный способ получения')->options(SourceMethod::class),
                ])
                ->columns(3),
            Section::make('Настройки площадки')
                ->description('Поля зависят от выбранного реестра и применяются только к этому заданию.')
                ->schema([
                    Fieldset::make('Настройки площадки')
                        ->columnSpanFull()
                        ->schema(fn (Get $get): array => self::platformComponents(get: $get)),
                ]),
            Section::make('Расписание и доступность')
                ->description('Пустое расписание не запускает задание по cron; активность можно включить после проверки настроек.')
                ->schema([
                    TextInput::make('schedule')
                        ->label('Расписание (cron)')
                        ->rules([
                            static function (): \Closure {
                                return static function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                                        $fail('Невалидное cron-выражение.');
                                    }
                                };
                            },
                        ])
                        ->columnSpanFull(),
                    Toggle::make('is_active')->label('Активно')->required(),
                ])
                ->columns(2),
            Section::make('Сетевой маршрут')
                ->schema([
                    Select::make('route_profile_id')->label('Профиль маршрутов')
                        ->relationship('routeProfile', 'profile_key')->searchable()->preload()->nullable()
                        ->helperText('Пустое поле наследует настроенный профиль по умолчанию. Недоступный профиль останавливает отправку.'),
                ]),
        ]);
    }

    /** @return array<int, mixed> */
    private static function platformComponents(Get $get): array
    {
        $registryId = $get('registry_id');

        if (! is_numeric($registryId)) {
            return [self::unavailablePlatformPlaceholder()];
        }

        $registry = Registry::find($registryId);

        if (! $registry instanceof Registry) {
            return [self::unavailablePlatformPlaceholder()];
        }

        try {
            $class = app(PlatformAdapterRegistry::class)->formClass(registry: $registry);

            /** @var CrawlerPlatformForm $form */
            $form = app()->make($class);

            return $form->components(registry: $registry, get: $get);
        } catch (Throwable) {
            return [self::unavailablePlatformPlaceholder()];
        }
    }

    private static function unavailablePlatformPlaceholder(): Placeholder
    {
        return Placeholder::make('platform_unavailable')
            ->label('Настройки площадки')
            ->content('Выберите реестр с настроенным platform adapter.');
    }
}
