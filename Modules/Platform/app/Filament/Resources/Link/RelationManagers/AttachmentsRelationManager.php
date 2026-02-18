<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\RelationManagers;

use App\MediaLibrary\DownloadHelper;
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
use Illuminate\Support\Facades\Storage;
use Platform\Enums\Crawling\CrawlingStatus;
use Platform\Enums\Platform as PlatformEnum;
use Platform\Models\Crawling\LinkAttachment;

final class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected ?string $pendingDocumentPath = null;

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
                            ->visible(fn(): bool => ! $this->isPlatformLocal())
                            ->default(fn(): ?string => $this->isPlatformLocal() ? '-' : null)
                            ->dehydrateStateUsing(fn(?string $state): string => $this->isPlatformLocal() ? '-' : ($state ?? '')),
                        TextInput::make('url')
                            ->label('URL')
                            ->url(fn(): bool => ! $this->isPlatformLocal())
                            ->maxLength(2048)
                            ->columnSpanFull()
                            ->visible(fn(): bool => ! $this->isPlatformLocal())
                            ->default(fn(): ?string => $this->isPlatformLocal() ? '-' : null)
                            ->dehydrateStateUsing(fn(?string $state): string => $this->isPlatformLocal() ? '-' : ($state ?? '')),
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
                            ->disk(config('app.filesystems.private_disk_name'))
                            ->dehydrated(true)
                            ->default(null),
                    ])
                    ->columns(1)
                    ->visible(fn(): bool => $this->isPlatformLocal()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn(LinkAttachment $record): string => $record->name ?: "Вложение #{$record->id}")
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('extension')
                    ->label('Расширение'),
                TextColumn::make('id')
                    ->label('Скачать')
                    ->placeholder('—')
                    ->url(fn(LinkAttachment $record): ?string => DownloadHelper::url($record->getFirstMedia('document')))
                    ->openUrlInNewTab(true)
                    ->formatStateUsing(function (mixed $state, LinkAttachment $record): string {
                        return $record->getFirstMedia('document') !== null ? 'Скачать' : '';
                    }),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(50)
                    ->url(fn($state) => $state, shouldOpenInNewTab: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Статус'),
                TextColumn::make('attempts')
                    ->label('Попытки')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        $this->pendingDocumentPath = $this->extractDocumentPath($data['document'] ?? null);
                        unset($data['document']);

                        return $data;
                    })
                    ->after(function (LinkAttachment $record): void {
                        $this->attachPendingDocumentToRecord($record);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(function (LinkAttachment $record): array {
                        $media = $record->getFirstMedia('document');
                        $documentPath = $media?->getPathRelativeToRoot();

                        return array_merge(
                            $record->attributesToArray(),
                            ['document' => $documentPath]
                        );
                    })
                    ->mutateDataUsing(function (array $data): array {
                        if ($this->isPlatformLocal()) {
                            $data['url'] = '-';
                            $data['extension'] = '-';
                        }
                        $this->pendingDocumentPath = $this->extractDocumentPath($data['document'] ?? null);
                        unset($data['document']);

                        return $data;
                    })
                    ->after(function (LinkAttachment $record): void {
                        $this->attachPendingDocumentToRecord($record);
                    }),
                DeleteAction::make(),
            ]);
    }

    private function isPlatformLocal(): bool
    {
        $code = $this->getOwnerRecord()->platform?->code ?? null;

        return $code === PlatformEnum::Local->value;
    }

    private function extractDocumentPath(mixed $document): ?string
    {
        if (empty($document)) {
            return null;
        }
        if (is_array($document)) {
            $document = $document[0] ?? null;
        }
        $path = (string) $document;
        if ($path === '' || str_starts_with($path, 'http')) {
            return null;
        }

        return $path;
    }

    private function attachPendingDocumentToRecord(LinkAttachment $record): void
    {
        if ($this->pendingDocumentPath === null) {
            return;
        }
        $record->clearMediaCollection('document');
        $disk = config('app.filesystems.private_disk_name');
        $fullPath = Storage::disk($disk)->path($this->pendingDocumentPath);
        if (is_file($fullPath)) {
            $record->addMedia($fullPath)->toMediaCollection(
                'document',
                config('app.filesystems.private_disk_name')
            );
        }
        $this->pendingDocumentPath = null;
    }
}
