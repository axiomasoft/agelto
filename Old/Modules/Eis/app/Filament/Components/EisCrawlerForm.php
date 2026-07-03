<?php

declare(strict_types=1);

namespace Eis\Filament\Components;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

final class EisCrawlerForm
{
    public static function schema(): array
    {
        return [
            Grid::make(4)
                ->schema([
                    Select::make('filter.sortBy')
                        ->label('Сортировка')
                        ->options([
                            'PRICE' => 'По цене',
                            'PUBLISH_DATE' => 'По дате размещения',
                            'UPDATE_DATE' => 'По дате обновления',
                        ])
                        ->default('PRICE'),

                    Select::make('filter.sortDirection')
                        ->label('Направление')
                        ->options([
                            'false' => 'По убыванию',
                            'true' => 'По возрастанию',
                        ])
                        ->default('false'),

                    Select::make('filter.recordsPerPage')
                        ->label('Записей на стр.')
                        ->options([
                            '_10' => '10',
                            '_50' => '50',
                            '_100' => '100',
                        ])
                        ->default('_50'),

                    // Здесь мы выбираем просто ключи: fz44, fz223, fz615
                    Select::make('filter.fz')
                        ->label('ФЗ')
                        ->multiple()
                        ->options([
                            'fz44' => '44-ФЗ',
                            'fz223' => '223-ФЗ',
                            'fz615' => '615-ПП',
                        ])
                        ->default(['fz44']),
                ]),

            Grid::make(2)
                ->schema([
                    TextInput::make('filter.priceFromGeneral')->label('Цена от')->numeric(),
                    TextInput::make('filter.priceToGeneral')->label('Цена до')->numeric(),
                ]),

            Grid::make(2)
                ->schema([
                    DatePicker::make('filter.publishDateFrom')->label('Размещение от')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('filter.publishDateTo')->label('Размещение до')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('filter.applSubmissionCloseDateFrom')->label('Подача от')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('filter.applSubmissionCloseDateTo')->label('Подача до')->native(false)->displayFormat('d.m.Y'),
                ]),
        ];
    }
}
