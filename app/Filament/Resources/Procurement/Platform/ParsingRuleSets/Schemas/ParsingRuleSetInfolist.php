<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Schemas;

use App\Models\Procurement\Parsing\ParsingRuleSet;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ParsingRuleSetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Метаданные набора правил')
                    ->schema([
                        TextEntry::make('platform.code')
                            ->label('Площадка'),
                        TextEntry::make('stage')
                            ->label('Стадия')
                            ->badge(),
                        TextEntry::make('registry_code')
                            ->label('Реестр')
                            ->placeholder('Все (default)'),
                        TextEntry::make('scope_key')
                            ->label('Scope'),
                        TextEntry::make('auto_heal_mode')
                            ->label('Режим самокоррекции')
                            ->badge(),
                        IconEntry::make('is_enabled')
                            ->label('Включён')
                            ->boolean(),
                        TextEntry::make('current_version_number')
                            ->label('Всего версий'),
                        TextEntry::make('activeVersion.version')
                            ->label('Активная версия')
                            ->formatStateUsing(static fn (mixed $state): string => is_int($state) || is_string($state) ? 'v'.(string) $state : '—')
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label('Обновлён')
                            ->dateTime(),
                    ])
                    ->columns(3),
                Section::make('JSON активной версии')
                    ->schema([
                        TextEntry::make('active_rules_json')
                            ->label('Конфигурация правил')
                            ->state(static function (ParsingRuleSet $record): string {
                                $active = $record->activeVersion;
                                if ($active === null) {
                                    return 'Активная версия отсутствует.';
                                }

                                $encoded = json_encode($active->rules_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                return is_string($encoded) ? $encoded : '{}';
                            })
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
