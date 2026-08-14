<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Schemas;

use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Models\Procurement\Platform\Platform;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

final class StatusMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Область применения')
                    ->description('Сопоставление относится либо ко всей площадке, либо к одному её реестру; после создания область фиксируется.')
                    ->schema([
                        Select::make('applicability')
                            ->label('Применимость')
                            ->options(StatusMappingApplicability::class)
                            ->live()
                            ->afterStateUpdated(function (Set $set, StatusMappingApplicability|string|null $state): void {
                                if ($state === StatusMappingApplicability::Platform || $state === StatusMappingApplicability::Platform->value) {
                                    $set('registry_id', null);
                                    $set('selected_platform_id', null);

                                    return;
                                }

                                $set('platform_id', null);
                            })
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(false),
                        Select::make('platform_id')
                            ->label('Площадка')
                            ->relationship('platform', 'name')
                            ->visible(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Platform, StatusMappingApplicability::Platform->value], true))
                            ->required(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Platform, StatusMappingApplicability::Platform->value], true))
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(),
                        Select::make('selected_platform_id')
                            ->label('Площадка реестра')
                            ->options(Platform::query()->pluck('name', 'id'))
                            ->visible(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Registry, StatusMappingApplicability::Registry->value], true))
                            ->required(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Registry, StatusMappingApplicability::Registry->value], true))
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('registry_id', null))
                            ->dehydrated(false),
                        Select::make('registry_id')
                            ->label('Реестр')
                            ->relationship(
                                name: 'registry',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('platform_id', $get('selected_platform_id')),
                            )
                            ->visible(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Registry, StatusMappingApplicability::Registry->value], true))
                            ->required(fn (Get $get): bool => in_array($get('applicability'), [StatusMappingApplicability::Registry, StatusMappingApplicability::Registry->value], true))
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(),
                    ])
                    ->columns(2),
                Section::make('Сопоставление статуса')
                    ->description('Этап площадки сохраняется как исходный факт и переводится в единый статус закупки.')
                    ->schema([
                        TextInput::make('source_stage')
                            ->label('Этап площадки')
                            ->required()
                            ->maxLength(255)
                            ->rules(fn (Get $get): array => [
                                Rule::unique('prc_status_mappings', 'source_stage')
                                    ->where(function (QueryBuilder $query) use ($get): QueryBuilder {
                                        return match ($get('applicability')) {
                                            StatusMappingApplicability::Platform, StatusMappingApplicability::Platform->value => $query->where('platform_id', $get('platform_id')),
                                            StatusMappingApplicability::Registry, StatusMappingApplicability::Registry->value => $query->where('registry_id', $get('registry_id')),
                                            default => $query->whereRaw('false'),
                                        };
                                    }),
                            ])
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(),
                        Select::make('canonical_status')
                            ->label('Канонический статус')
                            ->options(ProcurementStatus::class)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
