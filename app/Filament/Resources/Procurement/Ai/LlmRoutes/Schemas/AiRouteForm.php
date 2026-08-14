<?php

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Schemas;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Support\LlmPresentation;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AiRouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Назначение модели')
                    ->description('Выберите, для какого типа документа и шага AI будет использовать эту модель.')
                    ->schema([
                        Select::make('document_type')
                            ->label('Тип документа')
                            ->options([
                                ...LlmPresentation::documentTypeOptions(),
                                DocumentTypeScope::Any->value => LlmPresentation::documentType(DocumentTypeScope::Any),
                            ])
                            ->live()
                            ->required(),
                        Select::make('purpose')
                            ->label('Назначение')
                            ->options(function (Get $get) {
                                $value = $get('document_type');
                                $documentType = is_string($value) ? AiDocumentType::tryFrom($value) : null;

                                return LlmPresentation::purposeOptions(
                                    purposes: app(DocumentAnalyzerRegistry::class)->purposesFor($documentType ?? DocumentTypeScope::Any),
                                );
                            })
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Подключение и параметры')
                    ->description('Провайдер определяет способ обращения; параметры передаются модели для этой задачи.')
                    ->schema([
                        Select::make('provider_id')
                            ->label('Провайдер')
                            ->relationship('provider', 'id')
                            ->required(),
                        Textarea::make('model')
                            ->label('Модель')
                            ->required()
                            ->columnSpanFull(),
                        KeyValue::make('options')
                            ->label('Параметры')
                            ->helperText('Необязательные параметры конкретного провайдера; значения имеют приоритет только для этой модели.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Приоритет и доступность')
                    ->description('Активная модель с большим приоритетом выбирается первой; shadow-модель используется для сравнения.')
                    ->schema([
                        TextInput::make('priority')
                            ->label('Приоритет')
                            ->required()
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->required(),
                        Toggle::make('shadow')
                            ->label('Теневая')
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }
}
