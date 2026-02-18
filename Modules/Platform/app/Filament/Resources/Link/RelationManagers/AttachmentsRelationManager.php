<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Platform\Enums\Crawling\CrawlingStatus;
use Platform\Enums\Platform as PlatformEnum;
use Platform\Models\Crawling\LinkAttachment;

final class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Документы';

    protected static ?string $modelLabel = 'Документ';

    protected static ?string $pluralModelLabel = 'Документы';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->maxLength(255),
                        TextInput::make('extension')
                            ->label('Расширение')
                            ->maxLength(32)
                            ->visible(fn (): bool => ! $this->isPlatformLocal())
                            ->default(fn (): ?string => $this->isPlatformLocal() ? '-' : null)
                            ->dehydrateStateUsing(fn (?string $state): string => $this->isPlatformLocal() ? '-' : ($state ?? '')),
                        TextInput::make('url')
                            ->label('URL')
                            ->url(fn (): bool => ! $this->isPlatformLocal())
                            ->maxLength(2048)
                            ->columnSpanFull()
                            ->visible(fn (): bool => ! $this->isPlatformLocal())
                            ->default(fn (): ?string => $this->isPlatformLocal() ? '-' : null)
                            ->dehydrateStateUsing(fn (?string $state): string => $this->isPlatformLocal() ? '-' : ($state ?? '')),
                        TextInput::make('external_id')
                            ->label('Внешний ID')
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Статус')
                            ->options(CrawlingStatus::class)
                            ->default(CrawlingStatus::PENDING)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Файлы')
                    ->schema([
                        FileUpload::make('document')
                            ->label('Документ')
                            ->maxFiles(1)
                            ->maxSize(204800)
                            ->directory('link-attachments')
                            ->dehydrated(true)
                            ->default(null),
                    ])
                    ->columns(1)
                    ->visible(fn (): bool => $this->isPlatformLocal()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (LinkAttachment $record): string => $record->name ?: "Вложение #{$record->id}")
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
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
                    ->label('Статус'),
                TextColumn::make('attempts')
                    ->label('Попытки')
                    ->sortable(),
                TextColumn::make('parse_started_at')
                    ->label('Парсинг начат')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        if ($this->isPlatformLocal()) {
                            $data['url'] = '-';
                            $data['extension'] = '-';
                        }
                        unset($data['document']);

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (LinkAttachment $record): array => array_merge(
                        $record->attributesToArray(),
                        ['document' => null]
                    ))
                    ->mutateDataUsing(function (array $data): array {
                        if ($this->isPlatformLocal()) {
                            $data['url'] = '-';
                            $data['extension'] = '-';
                        }
                        unset($data['document']);

                        return $data;
                    }),
                DeleteAction::make(),
            ]);
    }

    private function isPlatformLocal(): bool
    {
        $code = $this->getOwnerRecord()->platform?->code ?? null;

        return $code === PlatformEnum::Local->value;
    }
}
