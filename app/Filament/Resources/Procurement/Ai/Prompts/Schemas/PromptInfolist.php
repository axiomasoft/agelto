<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Prompts\Schemas;

use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Models\Llm\Prompt;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PromptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Промт')
                    ->schema([
                        TextEntry::make('document_type')->label('Тип документа'),
                        TextEntry::make('key')->label('Ключ'),
                        TextEntry::make('step')->label('Шаг'),
                        TextEntry::make('version')->label('Версия'),
                        TextEntry::make('priority')->label('Приоритет'),
                        TextEntry::make('is_active')->label('Активен')->badge(),
                        TextEntry::make('is_protected')->label('Канон')->badge(),
                    ])
                    ->columns(3),
                Section::make('Тело промта')
                    ->schema([
                        TextEntry::make('content')
                            ->label('Полный текст')
                            ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])
                            ->columnSpanFull(),
                    ]),
                Section::make('Все версии этого промта')
                    ->description('Версии той же пары «тип документа + ключ», до 100 последних.')
                    ->schema([
                        RepeatableEntry::make('versions')
                            ->state(static fn (Prompt $record) => Prompt::query()
                                ->where('document_type', $record->document_type)
                                ->where('key', $record->key)
                                ->orderByDesc('version')
                                ->limit(100)
                                ->get())
                            ->schema([
                                TextEntry::make('version')
                                    ->label('Версия')
                                    ->url(static fn (Prompt $record): string => PromptResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('step')->label('Шаг'),
                                TextEntry::make('priority')->label('Приоритет'),
                                TextEntry::make('is_active')->label('Активен')->badge(),
                                TextEntry::make('is_protected')->label('Канон')->badge(),
                            ])
                            ->columns(5),
                    ]),
            ]);
    }
}
