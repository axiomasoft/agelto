<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Tables;

use App\Exceptions\Procurement\Ai\ManifestUnreadable;
use App\Filament\Support\LlmPresentation;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Бейдж «в манифесте» читает манифест ОДИН раз на рендер таблицы (`manifestState` вызывается
 * `configure`, не колонкой построчно) — та же дисциплина N+1, что у остальных ресурсов проекта.
 */
class FixturesTable
{
    public static function configure(Table $table): Table
    {
        $manifestState = self::manifestState();

        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('document_type')
                    ->label('Тип (вердикт)')
                    ->badge(),
                TextColumn::make('owner_document_type')
                    ->label('Владелец профиля')
                    ->badge()
                    ->visibleFrom('md'),
                TextColumn::make('source_format')
                    ->label('Формат')
                    ->badge()
                    ->visibleFrom('md'),
                IconColumn::make('is_verified')
                    ->label('Проверен')
                    ->boolean(),
                TextColumn::make('in_manifest')
                    ->label('Манифест')
                    ->state(function (Fixture $record) use ($manifestState): string {
                        if (! $manifestState['available']) {
                            return 'манифест недоступен';
                        }

                        return in_array($record->code, $manifestState['codes'], true) ? 'в манифесте' : 'нет в манифесте';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'в манифесте' => 'success',
                        'нет в манифесте' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('gold')
                    ->label('Золото')
                    ->state(function (Fixture $record) use ($manifestState): string {
                        if (! $manifestState['available']) {
                            return 'манифест недоступен';
                        }

                        return in_array($record->code, $manifestState['goldCodes'], true) ? 'есть' : 'нет';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'есть' => 'success',
                        'нет' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('document_type')
                    ->label('Тип (вердикт)')
                    ->options(LlmPresentation::documentTypeOptions()),
                SelectFilter::make('owner_document_type')
                    ->label('Владелец профиля')
                    ->options(LlmPresentation::documentTypeOptions()),
                // Витрина НЕ знает имён и множеств значений полей профиля — фильтр по
                // `type_profile` целиком генерический: оператор вводит ИМЯ произвольного поля
                // профиля и искомое значение, экран строит выражение по этому ключу сам не зная,
                // что это за поле .
                Filter::make('profile_field')
                    ->label('Поле профиля')
                    ->schema([
                        TextInput::make('key')->label('Имя поля профиля'),
                        TextInput::make('value')->label('Значение'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['key'] ?? null) || ! filled($data['value'] ?? null)) {
                            return $query;
                        }

                        // Ключ — операторский ввод, не литерал кода: параметризован через
                        // `whereRaw`, НЕ конкатенирован в путь `column->key` (та форма ушла бы
                        // в SQL без биндинга и открыла бы инъекцию через это самое поле).
                        return $query->whereRaw('type_profile ->> ? = ?', [$data['key'], $data['value']]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array{available: bool, codes: list<string>, goldCodes: list<string>}
     */
    private static function manifestState(): array
    {
        try {
            $manifestPath = config('ai.fixtures.manifest_path');

            if (! is_string($manifestPath) || $manifestPath === '') {
                return ['available' => false, 'codes' => [], 'goldCodes' => []];
            }

            $manifest = app(FixtureManifestReader::class)->read($manifestPath);
        } catch (ManifestUnreadable) {
            return ['available' => false, 'codes' => [], 'goldCodes' => []];
        }

        $codes = collect($manifest['fixtures'])
            ->map(static fn (mixed $entry): ?string => is_array($entry) && is_string($entry['code'] ?? null) ? $entry['code'] : null)
            ->filter()
            ->values()
            ->all();

        $goldCodes = collect($manifest['fixtures'])
            ->filter(static fn (mixed $entry): bool => is_array($entry) && filled($entry['gold_path'] ?? null))
            ->map(static fn (array $entry): ?string => is_string($entry['code'] ?? null) ? $entry['code'] : null)
            ->filter()
            ->values()
            ->all();

        /** @var list<string> $codes */
        /** @var list<string> $goldCodes */
        return ['available' => true, 'codes' => $codes, 'goldCodes' => $goldCodes];
    }
}
