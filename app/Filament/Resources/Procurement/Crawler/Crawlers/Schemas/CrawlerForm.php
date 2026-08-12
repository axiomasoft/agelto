<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Filament\Resources\Procurement\Crawler\Proxies\Schemas\ProxyForm;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\PlatformAdapterRegistry;
use Cron\CronExpression;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

final class CrawlerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('registry_id')->relationship('registry', 'name')->live()->required(),
            Textarea::make('name')->required()->columnSpanFull(),
            Select::make('mode')->options(CrawlerMode::class)->required(),
            Select::make('execution_lane')
                ->label('Контур исполнения')
                ->options([
                    CrawlerExecutionLane::Standard->value => 'Стандартный — до 5 минут',
                    CrawlerExecutionLane::Long->value => 'Длинный архивный — до 1 часа',
                ])
                ->default(CrawlerExecutionLane::Standard->value)
                ->required(),
            Select::make('stop_policy')->label('Режим работы')->options(RunStopPolicy::class)->required(),
            Select::make('source_method')->options(SourceMethod::class)->required(),
            Select::make('fallback_method')->options(SourceMethod::class),
            Fieldset::make('Настройки площадки')
                ->columnSpanFull()
                ->schema(fn (Get $get): array => self::platformComponents(get: $get)),
            TextInput::make('schedule')
                ->label('Расписание (cron)')
                ->rules([
                    static function (): \Closure {
                        return static function (string $attribute, mixed $value, \Closure $fail): void {
                            if (blank($value)) {
                                return;
                            }

                            if (! CronExpression::isValidExpression((string) $value)) {
                                $fail('Невалидное cron-выражение.');
                            }
                        };
                    },
                ])
                ->columnSpanFull(),
            Toggle::make('is_active')->required(),
            Select::make('proxy_mode')
                ->label('Режим прокси')
                ->options([
                    CrawlerProxyMode::Profile->value => 'Профиль: использовать привязанные узлы',
                    CrawlerProxyMode::Default->value => 'По умолчанию: наследовать настройку краулера',
                    CrawlerProxyMode::Direct->value => 'Напрямую: не использовать прокси',
                ])
                ->default(CrawlerProxyMode::Default->value)
                ->live()
                ->required(),
            Select::make('proxies')
                ->label('Разрешённые прокси')
                ->relationship('proxies', 'host')
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $get('proxy_mode') === CrawlerProxyMode::Profile->value),
            Select::make('proxy_country')
                ->label('Предпочитаемая страна прокси')
                ->options(ProxyForm::COUNTRIES)
                ->visible(fn (Get $get): bool => $get('proxy_mode') === CrawlerProxyMode::Profile->value),
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

            return app($class)->components(registry: $registry, get: $get);
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
