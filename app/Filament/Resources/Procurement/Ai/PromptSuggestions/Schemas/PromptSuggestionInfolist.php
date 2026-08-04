<?php

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions\Schemas;

use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Repositories\Llm\PromptReadRepository;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка предложения (P5.6): обоснование критика, свидетельства и предложенный текст РЯДОМ с
 * текущим телом промта. Тексты выводятся как ДАННЫЕ (`TextEntry` экранирует, `->html()` нигде не
 * зовётся): и `evidence`, и `proposed_content` пришли от критика по недоверенному содержимому
 * документа. Текущее тело читается из носителя промтов (`PromptReadRepository`, домен `Llm`), а не
 * из файла канона: экран в файловую систему не ходит (D47).
 */
class PromptSuggestionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Решение')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge(),
                        TextEntry::make('decidedBy.name')
                            ->label('Решил')
                            ->placeholder('-'),
                        TextEntry::make('decided_at')
                            ->label('Когда')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('decision_note')
                            ->label('Причина / заметка')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                // Расхождение «БД говорит approved, файл прежний» обязано быть ВИДНО (D47):
                // невидимое — источник ложной уверенности, что промт уже изменился.
                Section::make('Одобрено, но не применено')
                    ->description('Решение принято, файл промта ещё прежний — патч применяет команда, не экран.')
                    ->schema([
                        TextEntry::make('apply_command')
                            ->label('Команда применения')
                            ->state(static fn (PromptSuggestion $record): string => self::applyCommand($record))
                            ->copyable()
                            ->columnSpanFull(),
                        TextEntry::make('apply_hint')
                            ->label('После применения')
                            ->state('Закоммитить изменённый файл промта и прогнать `ai:prompts:sync` — БД получит новую версию из канона.')
                            ->columnSpanFull(),
                    ])
                    ->visible(static fn (PromptSuggestion $record): bool => $record->status === PromptSuggestionStatus::Approved),

                Section::make('Предмет')
                    ->schema([
                        TextEntry::make('kind')
                            ->label('Форма')
                            ->badge(),
                        TextEntry::make('document_type')
                            ->label('Тип документа'),
                        TextEntry::make('analysis_step')
                            ->label('Стадия')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('prompt_key')
                            ->label('Ключ промта')
                            ->placeholder('-'),
                        TextEntry::make('prompt_step')
                            ->label('Шаг промта')
                            ->placeholder('-'),
                        TextEntry::make('base_version')
                            ->label('Базовая версия')
                            ->placeholder('-'),
                        TextEntry::make('origin')
                            ->label('Происхождение')
                            ->state(static fn (PromptSuggestion $record): string => PromptSuggestionResource::origin($record))
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Обоснование критика')
                    ->schema([
                        TextEntry::make('rationale')
                            ->label('Почему')
                            ->columnSpanFull(),
                        TextEntry::make('evidence')
                            ->label('Свидетельства')
                            ->state(static fn (PromptSuggestion $record): string => $record->evidence->implode(', '))
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('hint')
                            ->label('Подсказка')
                            ->placeholder('-')
                            ->visible(static fn (PromptSuggestion $record): bool => $record->kind === PromptSuggestionKind::CorrectionHint)
                            ->columnSpanFull(),
                    ]),

                Section::make('Тексты промта')
                    ->schema([
                        TextEntry::make('current_content')
                            ->label('Текущее тело промта')
                            ->state(static fn (PromptSuggestion $record): ?string => self::currentContent($record))
                            ->placeholder('промт этой версии в БД не найден — прогони ai:prompts:sync'),
                        TextEntry::make('proposed_content')
                            ->label('Предложенный текст')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->visible(static fn (PromptSuggestion $record): bool => $record->kind === PromptSuggestionKind::PromptPatch),
            ]);
    }

    /**
     * Точная строка, которой предложение применяется, — собирается из id записи, а не хардкодится
     * списком: подсказка, которую нельзя выполнить дословно, хуже отсутствующей.
     */
    private static function applyCommand(PromptSuggestion $record): string
    {
        return "docker compose exec app php artisan ai:prompts:apply {$record->id}";
    }

    /**
     * Тело промта той версии, ПРОТИВ которой критик писал патч (`document_type` предложения для
     * формы `prompt_patch` — скоуп шага, то есть ровно поле строки промта).
     */
    private static function currentContent(PromptSuggestion $record): ?string
    {
        if ($record->prompt_key === null || $record->base_version === null) {
            return null;
        }

        $prompt = app(PromptReadRepository::class)->findVersion(
            documentType: $record->document_type,
            key: $record->prompt_key,
            version: $record->base_version,
        );

        return $prompt?->content;
    }
}
