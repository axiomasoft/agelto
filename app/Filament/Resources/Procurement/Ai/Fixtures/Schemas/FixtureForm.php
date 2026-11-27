<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Support\LlmPresentation;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\SupportsTypeProfileValidation;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Форма из девяти полей: файл, `title`, обе оси типа, `type_profile`
 * (generic-редактор), `notes`, `quirks`, `generator`, `cluster_id`. Экран НЕ знает полей
 * сметы — форму профиля проверяет анализатор типа через `SupportsTypeProfileValidation`, витрина только показывает готовые сообщения.
 */
class FixtureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Исходный эталон')
                    ->description('Файл и название задают эталонный документ для проверки AI.')
                    ->schema([
                        FileUpload::make('source')
                            ->label('Файл эталона')
                            ->required()
                            ->maxSize(51200)
                            ->rules(['extensions:xlsx,xls,pdf,gsfx,docx,jpg'])
                            ->disk(self::fixturesDisk())
                            ->storeFiles(false)
                            ->previewable(false)
                            ->visibleOn('create'),
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Профиль проверки')
                    ->description('Тип документа задаёт ожидаемый результат, а профиль проверяется выбранным анализатором.')
                    ->schema([
                        Select::make('document_type')
                            ->label('Тип документа (ожидаемый вердикт скрининга)')
                            ->options(LlmPresentation::documentTypeOptions())
                            ->required(),
                        Select::make('owner_document_type')
                            ->label('Владелец профиля')
                            ->options(LlmPresentation::documentTypeOptions())
                            ->required()
                            ->live(),
                        KeyValue::make('type_profile')
                            ->label('Профиль типа')
                            ->helperText('Структура зависит от владельца профиля и валидируется анализатором до сохранения.')
                            ->required()
                            ->columnSpanFull()
                            ->rules(fn (Get $get): array => [self::typeProfileValidationRule($get)]),
                    ])
                    ->columns(2),
                Section::make('Рабочие пометки')
                    ->description('Эти сведения помогают операторам группировать и воспроизводить проверку эталона.')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Комментарий')
                            ->columnSpanFull(),
                        TagsInput::make('quirks')
                            ->label('Квирки'),
                        TextInput::make('generator')
                            ->label('Генератор'),
                        TextInput::make('cluster_id')
                            ->label('Кластер'),
                    ])
                    ->columns(3),
            ]);
    }

    /** @return Closure(string, mixed, Closure(string): mixed): void */
    private static function typeProfileValidationRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $ownerTypeValue = $get('owner_document_type');
            $ownerType = is_string($ownerTypeValue) ? AiDocumentType::tryFrom($ownerTypeValue) : null;

            if ($ownerType === null) {
                return;
            }

            $analyzer = app(DocumentAnalyzerRegistry::class)->for($ownerType);

            if (! $analyzer instanceof SupportsTypeProfileValidation) {
                return;
            }

            if (! is_array($value)) {
                $fail('Профиль типа должен быть объектом.');

                return;
            }

            /** @var array<string, mixed> $value */
            $errors = $analyzer->validateTypeProfile($value);

            foreach ($errors as $messages) {
                foreach ($messages as $message) {
                    $fail($message);
                }
            }
        };
    }

    private static function fixturesDisk(): string
    {
        $disk = config('ai.fixtures.disk');

        if (! is_string($disk) || $disk === '') {
            throw new \UnexpectedValueException('ai.fixtures.disk must be a non-empty string.');
        }

        return $disk;
    }
}
