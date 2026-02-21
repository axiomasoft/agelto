<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\LinkAttachment\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Platform\Models\Crawling\LinkAttachment;

final class LinkAttachmentsTable
{
    private const EXTENSION_SUGGESTIONS = [
        '7z',
        'BMP',
        'doc',
        'htm',
        'html',
        'jpeg',
        'jpg',
        'odt',
        'ods',
        'pdf',
        'rar',
        'rtf',
        'tiff',
        'txt',
        'xls',
        'xlsx',
        'xml',
        'zip',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->recordTitle(fn (LinkAttachment $record): string => $record->name ?: "Вложение #{$record->id}")
            ->columns([
                TextColumn::make('link.reg_number')
                    ->label('Номер заявки')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Название вложения')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('extension')
                    ->label('Расширение'),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(50)
                    ->url(fn ($state) => $state, shouldOpenInNewTab: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('link_id')
                    ->relationship(name: 'link', titleAttribute: 'reg_number')
                    ->label('Ссылка')
                    ->searchable()
                    ->preload(),
                Filter::make('extension')
                    ->schema([
                        Select::make('extensions')
                            ->label('Расширения из списка')
                            ->options(array_combine(self::EXTENSION_SUGGESTIONS, self::EXTENSION_SUGGESTIONS))
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        TextInput::make('extensions_custom')
                            ->label('Доп. расширения')
                            ->placeholder('Через запятую, например: abc, def')
                            ->helperText('Свои значения — по полному совпадению'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $fromSelect = $data['extensions'] ?? [];
                        $custom = $data['extensions_custom'] ?? '';
                        $customList = $custom !== ''
                            ? array_map('trim', explode(',', $custom))
                            : [];
                        $extensions = array_unique(array_filter(array_merge(
                            is_array($fromSelect) ? $fromSelect : [],
                            $customList,
                        )));
                        if ($extensions === []) {
                            return $query;
                        }

                        return $query->whereIn('extension', $extensions);
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
