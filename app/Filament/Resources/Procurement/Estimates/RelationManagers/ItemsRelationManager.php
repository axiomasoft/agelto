<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\RelationManagers;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Enums\Procurement\Estimate\RowQualityReason;
use App\Models\Procurement\Estimate\Item;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('name')
                    ->label('Наименование')
                    ->wrap()
                    ->lineClamp(3)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('unit')
                    ->label('Ед. изм.')
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('quantity')
                    ->label('Кол-во')
                    ->numeric()
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('unit_price_curr')
                    ->label('Цена')
                    ->money('RUB')
                    ->placeholder('-')
                    ->visibleFrom('md'),
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
                SelectFilter::make('quality_reason')
                    ->label('Причина пометки')
                    ->options(collect(RowQualityReason::cases())
                        ->mapWithKeys(static fn (RowQualityReason $reason): array => [$reason->value => $reason->label()])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $reason = $data['value'] ?? null;

                        if (! is_string($reason) || $reason === '') {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($reason): void {
                            $query
                                ->whereJsonContains('quality->validator->reasons', $reason)
                                ->orWhere('quality->model->reason', $reason);
                        });
                    }),
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
                ->map(static fn (string $field): string => EstimateRow::fieldLabel($field))
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
