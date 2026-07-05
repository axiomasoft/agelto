<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentAnalysis\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class DocumentAnalysisForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->columnSpanFull()
                            ->maxLength(500),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'pending' => 'Ожидает',
                                'processing' => 'В обработке',
                                'completed' => 'Завершён',
                                'failed' => 'Ошибка',
                            ])
                            ->default('pending')
                            ->hiddenOn('create')
                            ->disabled(),
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
                            ->default(null)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $path = is_array($state) ? ($state[0] ?? '') : (string) $state;
                                $set('extension', $path !== '' ? pathinfo($path, PATHINFO_EXTENSION) : null);
                            }),
                        TextInput::make('extension')
                            ->label('Расширение')
                            ->maxLength(12)
                            ->readOnly()
                            ->dehydrated(true),
                    ])
                    ->columns(1),
                Section::make('Системная информация')
                    ->schema([
                        DatePicker::make('completed_at')
                            ->label('Завершён')
                            ->readOnly()
                            ->native(false),
                        TextInput::make('attempts')
                            ->label('Попытки')
                            ->numeric()
                            ->default(0)
                            ->readOnly(),
                        TextInput::make('last_error')
                            ->label('Последняя ошибка')
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->visibleOn('edit'),
            ]);
    }
}
