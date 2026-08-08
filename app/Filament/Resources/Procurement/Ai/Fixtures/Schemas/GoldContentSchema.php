<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use App\Dto\Procurement\Gold\GoldDocumentView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Единый read-only рендер формы gold §8.2 для файлового и будущего dataset-слоёв. */
final class GoldContentSchema
{
    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'estimate_number' => 'Смета №',
        'currency' => 'Валюта',
        'base_year' => 'Базовый год',
        'price_level' => 'Уровень цен',
        'regulatory_base' => 'Нормативная база',
        'rows_expected' => 'Ожидалось позиций',
        'calc_method' => 'Метод расчёта',
        'address' => 'Адрес',
        'generator' => 'Программа',
        'reference' => 'Основание',
        'row_index' => '№ строки',
        'row_type' => 'Тип строки',
        'section_title' => 'Раздел',
        'code' => 'Обоснование',
        'name' => 'Наименование',
        'unit' => 'Ед.',
        'quantity' => 'Количество',
        'unit_price_base' => 'Цена базовая',
        'unit_price_curr' => 'Цена текущая',
        'total_price_base' => 'Сумма базовая',
        'total_price_curr' => 'Сумма текущая',
        'resources' => 'Подэлементы',
    ];

    public static function table(Table $table, GoldDocumentView $document): Table
    {
        return $table
            ->records(fn (): array => self::rows($document))
            ->columns([
                TextColumn::make('row_index')->label(self::FIELD_LABELS['row_index']),
                TextColumn::make('row_type')->label(self::FIELD_LABELS['row_type'])->badge(),
                TextColumn::make('section_title')->label(self::FIELD_LABELS['section_title'])->placeholder('—'),
                TextColumn::make('code')->label(self::FIELD_LABELS['code'])->placeholder('—'),
                TextColumn::make('name')->label(self::FIELD_LABELS['name'])->wrap(),
                TextColumn::make('unit')->label(self::FIELD_LABELS['unit'])->placeholder('—'),
                TextColumn::make('quantity')->label(self::FIELD_LABELS['quantity'])->placeholder('—'),
                TextColumn::make('unit_price_base')->label(self::FIELD_LABELS['unit_price_base'])->placeholder('—'),
                TextColumn::make('unit_price_curr')->label(self::FIELD_LABELS['unit_price_curr'])->placeholder('—'),
                TextColumn::make('total_price_base')->label(self::FIELD_LABELS['total_price_base'])->placeholder('—'),
                TextColumn::make('total_price_curr')->label(self::FIELD_LABELS['total_price_curr'])->placeholder('—'),
                TextColumn::make('resources')
                    ->label(self::FIELD_LABELS['resources'])
                    ->listWithLineBreaks()
                    ->expandableLimitedList()
                    ->placeholder('—'),
            ]);
    }

    /** @return list<array<string, mixed>> */
    public static function rows(GoldDocumentView $document): array
    {
        return $document->rows();
    }
}
