<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Platform\PlatformUiResolver;
use App\Services\Procurement\Proxyma\Panel\SettingsSections;
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
use Illuminate\Database\Eloquent\Model;
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
                    Select::make('listing_kind')->label('Режим')->options(['fresh' => 'Новые извещения', 'archive' => 'Архив', 'delta' => 'Изменения'])->required(),
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
            Section::make('Proxyma')->schema(fn (?Model $record): array => app(SettingsSections::class)->forRecord($record))->columnSpanFull(),
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
            $code = $registry->platform?->getAttribute('code');
            if (! is_string($code)) {
                return [self::unavailablePlatformPlaceholder()];
            }

            return app(PlatformUiResolver::class)->resolve(new PlatformCode($code))?->profileFormSchema() ?? [];
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
