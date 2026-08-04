<?php

namespace App\Filament\Resources\Procurement\Ai\Prompts\Schemas;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('is_protected_hint')
                    ->label('')
                    ->content('Канонические промты (is_protected) правятся только через git + db:seed. Здесь создаются только операторские дополняющие записи — они не защищены.')
                    ->columnSpanFull(),
                Select::make('document_type')
                    ->options([
                        ...collect(AiDocumentType::cases())->mapWithKeys(
                            static fn (AiDocumentType $case): array => [$case->value => $case->value],
                        )->all(),
                        DocumentTypeScope::Any->value => DocumentTypeScope::Any->value,
                    ])
                    ->live()
                    ->required(),
                Textarea::make('key')
                    ->required()
                    ->columnSpanFull(),
                Select::make('step')
                    ->options(function (Get $get) {
                        $documentType = AiDocumentType::tryFrom((string) $get('document_type')) ?? DocumentTypeScope::Any;

                        return collect(app(DocumentAnalyzerRegistry::class)->promptStepsFor($documentType))
                            ->mapWithKeys(static fn ($step): array => [$step->value => $step->value])
                            ->all();
                    })
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('content')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('version')
                    ->required()
                    ->numeric(),
                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
