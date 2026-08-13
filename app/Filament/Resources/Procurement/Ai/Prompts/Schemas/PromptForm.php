<?php

namespace App\Filament\Resources\Procurement\Ai\Prompts\Schemas;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Support\LlmPresentation;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Статус инструкции')
                    ->description('Канонические инструкции защищены: в панели создаются только операторские дополнения.')
                    ->schema([
                        Placeholder::make('is_protected_hint')
                            ->label('')
                            ->content('Канонические промты (is_protected) правятся только через git + db:seed. Здесь создаются только операторские дополняющие записи — они не защищены.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Назначение инструкции')
                    ->description('Свяжите инструкцию с типом документа и этапом обработки.')
                    ->schema([
                        Select::make('document_type')
                            ->label('Тип документа')
                            ->options([
                                ...LlmPresentation::documentTypeOptions(),
                                DocumentTypeScope::Any->value => LlmPresentation::documentType(DocumentTypeScope::Any),
                            ])
                            ->live()
                            ->required(),
                        Textarea::make('key')
                            ->label('Ключ')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('step')
                            ->label('Этап')
                            ->options(function (Get $get) {
                                $documentType = AiDocumentType::tryFrom((string) $get('document_type')) ?? DocumentTypeScope::Any;

                                return LlmPresentation::promptStepOptions(
                                    steps: app(DocumentAnalyzerRegistry::class)->promptStepsFor($documentType),
                                );
                            })
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Текст инструкции')
                    ->description('Текст передаётся AI для выбранного шага; проверьте его перед сохранением.')
                    ->schema([
                        Textarea::make('content')
                            ->label('Текст инструкции')
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make('Версия и включение')
                    ->description('Приоритет определяет порядок применения; неактивная запись не участвует в сборке инструкции.')
                    ->schema([
                        TextInput::make('version')
                            ->label('Версия')
                            ->required()
                            ->numeric(),
                        TextInput::make('priority')
                            ->label('Приоритет')
                            ->required()
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }
}
