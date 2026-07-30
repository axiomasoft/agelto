<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use App\Enums\Procurement\AiDocumentType;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\SupportsTypeProfileValidation;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Форма из девяти полей (P5.1, D133): файл, `title`, обе оси типа, `type_profile`
 * (generic-редактор, D43), `notes`, `quirks`, `generator`, `cluster_id`. Экран НЕ знает полей
 * сметы — форму профиля проверяет анализатор типа через `SupportsTypeProfileValidation`
 * (Implementation Rule 5, D132), витрина только показывает готовые сообщения.
 */
class FixtureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('source')
                    ->label('Файл эталона')
                    ->required()
                    ->disk((string) config('ai.fixtures.disk'))
                    ->storeFiles(false)
                    ->previewable(false)
                    ->visibleOn('create'),
                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),
                Select::make('document_type')
                    ->label('Тип документа (ожидаемый вердикт скрининга)')
                    ->options(collect(AiDocumentType::cases())->pluck('value', 'value')->all())
                    ->required(),
                Select::make('owner_document_type')
                    ->label('Владелец профиля')
                    ->options(collect(AiDocumentType::cases())->pluck('value', 'value')->all())
                    ->required()
                    ->live(),
                KeyValue::make('type_profile')
                    ->label('Профиль типа')
                    ->required()
                    ->columnSpanFull()
                    ->rules(fn (Get $get): array => [self::typeProfileValidationRule($get)]),
                Textarea::make('notes')
                    ->label('Комментарий')
                    ->columnSpanFull(),
                TagsInput::make('quirks')
                    ->label('Квирки'),
                TextInput::make('generator')
                    ->label('Генератор'),
                TextInput::make('cluster_id')
                    ->label('Кластер'),
            ]);
    }

    private static function typeProfileValidationRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $ownerType = AiDocumentType::tryFrom((string) $get('owner_document_type'));

            if ($ownerType === null) {
                return;
            }

            $analyzer = app(DocumentAnalyzerRegistry::class)->for($ownerType);

            if (! $analyzer instanceof SupportsTypeProfileValidation) {
                return;
            }

            $errors = $analyzer->validateTypeProfile((array) $value);

            foreach ($errors as $messages) {
                foreach ($messages as $message) {
                    $fail($message);
                }
            }
        };
    }
}
