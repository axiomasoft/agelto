<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\RelationManagers;

use App\Enums\Procurement\Estimate\RowQualityReason;
use App\Models\Procurement\Estimate\Item;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Таблица позиций сметы с колонкой пометки неопределённости и фильтром «только помеченные»
 * (P8.5, Scope Included п.2). Заведён вместо секции инфолиста `EstimateInfolist` — фильтр
 * («только помеченные») требует table-фильтров, которых у секции инфолиста нет по конструкции
 * Filament (`RepeatableEntry` не несёт `->filters()`), а `AnalysisInfolist::estimates` показывает
 * плоский список без интерактивности — здесь она обязательна по Scope, поэтому образец
 * `Procurements/` (Resource + Pages/ + Schemas/ + Tables/ без relation manager) не покрывает
 * задачу и заводится `RelationManagers/`, как уже сделано для `Ai/Analyses`
 * (`CorrectionsRelationManager`) на том же ресурсе-доноре читаемости.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    /** Подписи полей `EstimateRow`, которых касается пометка модели (§3.1 research/12) — единственный носитель перевода в человекочитаемые подписи колонок сметы (Code Guidance П8.5). */
    private const array FIELD_LABELS = [
        'name' => 'Наименование',
        'section_title' => 'Раздел',
        'code' => 'Шифр расценки',
        'unit' => 'Ед. изм.',
        'quantity' => 'Количество',
        'unit_price_base' => 'Цена (базисный уровень)',
        'unit_price_curr' => 'Цена (текущий уровень)',
        'total_price_base' => 'Стоимость (базисный уровень)',
        'total_price_curr' => 'Стоимость (текущий уровень)',
        'index_code' => 'Индекс (код)',
        'index_value' => 'Индекс (значение)',
        'source_position_number' => '№ позиции в документе',
        'work_type_name' => 'Вид работ',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('section')->orderBy('row_index'))
            ->columns([
                TextColumn::make('row_index')
                    ->label('№'),
                TextColumn::make('section.title')
                    ->label('Раздел')
                    ->placeholder('-'),
                TextColumn::make('name')
                    ->label('Наименование')
                    ->wrap(),
                TextColumn::make('unit')
                    ->label('Ед. изм.')
                    ->placeholder('-'),
                TextColumn::make('quantity')
                    ->label('Кол-во')
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('unit_price_curr')
                    ->label('Цена')
                    ->money('RUB')
                    ->placeholder('-'),
                TextColumn::make('total_price_curr')
                    ->label('Стоимость')
                    ->money('RUB')
                    ->placeholder('-'),
                TextColumn::make('quality')
                    ->label('Пометка')
                    ->state(fn (Item $record): array => self::qualityLines($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                Filter::make('marked')
                    ->label('Только помеченные')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('quality')),
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }

    /**
     * Провенанс различим оператору двумя подписями (Implementation Rules п.3 item P8.5) —
     * «не прочитано моделью» / «нашла проверка сметы», ровно формулировки §4 research/12.
     *
     * @return list<string>
     */
    private static function qualityLines(Item $record): array
    {
        /** @var Collection<string, mixed>|null $quality */
        $quality = $record->quality;

        if ($quality === null) {
            return [];
        }

        $lines = [];

        $model = $quality->get('model');

        if (is_array($model)) {
            $reason = RowQualityReason::from($model['reason']);
            $fields = collect($model['fields'])
                ->map(static fn (string $field): string => self::FIELD_LABELS[$field] ?? $field)
                ->implode(', ');

            $lines[] = "Не прочитано моделью: {$reason->label()} ({$fields})";
        }

        $validator = $quality->get('validator');

        if (is_array($validator)) {
            $reasons = collect($validator['reasons'])
                ->map(static fn (string $reason): string => RowQualityReason::from($reason)->label())
                ->implode(', ');

            $lines[] = "Нашла проверка сметы: {$reasons}";
        }

        return $lines;
    }
}
