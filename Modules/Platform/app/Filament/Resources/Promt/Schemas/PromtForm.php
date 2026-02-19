<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Promt\Schemas;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Platform\Dto\PromtRule\Group;
use Platform\Models\Promt\PromptRule;

final class PromtForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('key')
                            ->label('Ключ')
                            ->required()
                            ->maxLength(255)
                            ->unique(PromptRule::class, 'key', ignoreRecord: true),
                        Select::make('group')
                            ->label('Группа')
                            ->options(Group::options())
                            ->required()
                            ->searchable(),
                        TextInput::make('priority')
                            ->label('Приоритет')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        TextInput::make('version')
                            ->label('Версия')
                            ->numeric()
                            ->default(1)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Активно')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Содержание')
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('Текст правила')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
