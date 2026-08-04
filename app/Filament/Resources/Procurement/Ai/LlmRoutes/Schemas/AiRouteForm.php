<?php

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Schemas;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AiRouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_type')
                    ->options([
                        ...collect(AiDocumentType::cases())->mapWithKeys(
                            static fn (AiDocumentType $case): array => [$case->value => $case->value],
                        )->all(),
                        DocumentTypeScope::Any->value => DocumentTypeScope::Any->value,
                    ])
                    ->live()
                    ->required(),
                Select::make('purpose')
                    ->options(function (Get $get) {
                        $documentType = AiDocumentType::tryFrom((string) $get('document_type')) ?? DocumentTypeScope::Any;

                        return collect(app(DocumentAnalyzerRegistry::class)->purposesFor($documentType))
                            ->mapWithKeys(static fn ($purpose): array => [$purpose->value => $purpose->value])
                            ->all();
                    })
                    ->required(),
                Select::make('provider_id')
                    ->relationship('provider', 'id')
                    ->required(),
                Textarea::make('model')
                    ->required()
                    ->columnSpanFull(),
                KeyValue::make('options')
                    ->columnSpanFull(),
                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('shadow')
                    ->required(),
            ]);
    }
}
