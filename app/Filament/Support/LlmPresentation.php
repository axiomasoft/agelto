<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Ai\AiDocumentType;

final class LlmPresentation
{
    public static function documentType(AiDocumentType|DocumentTypeScope $type): string
    {
        return match ($type) {
            DocumentTypeScope::Any => 'Любой тип документа',
            AiDocumentType::Estimate => 'Смета',
            AiDocumentType::TechSpec => 'Техническое задание',
            AiDocumentType::ContractDraft => 'Проект договора',
            AiDocumentType::Protocol => 'Протокол',
            AiDocumentType::Unknown => 'Не определён',
        };
    }

    public static function promptStep(PromptStep $step): string
    {
        return match ($step->value) {
            'global' => 'Общие правила',
            'screening:global' => 'Общие правила скрининга',
            'screening:classify' => 'Определение типа документа',
            'prompt:critic' => 'Проверка инструкций',
            'extraction:global' => 'Общие правила извлечения',
            'extraction:discovery' => 'Поиск структуры сметы',
            'extraction:passport' => 'Паспорт сметы',
            'extraction:rows' => 'Позиции сметы',
            'extraction:totals' => 'Итоги сметы',
            default => $step->value,
        };
    }

    public static function purpose(LlmPurpose $purpose): string
    {
        return match ($purpose->value) {
            'screening' => 'Скрининг документа',
            'eval' => 'Проверка качества',
            'shadow' => 'Теневая проверка',
            'prompt_critic' => 'Проверка инструкций',
            'discovery' => 'Поиск структуры',
            'extract_passport' => 'Паспорт документа',
            'extract_rows' => 'Извлечение позиций',
            'extract_totals' => 'Извлечение итогов',
            default => $purpose->value,
        };
    }

    /** @return array<string, string> */
    public static function documentTypeOptions(): array
    {
        return collect(AiDocumentType::cases())
            ->mapWithKeys(static fn (AiDocumentType $type): array => [$type->value => self::documentType($type)])
            ->all();
    }

    /** @param iterable<PromptStep> $steps
     * @return array<string, string>
     */
    public static function promptStepOptions(iterable $steps): array
    {
        return collect(array_values(iterator_to_array($steps)))
            ->mapWithKeys(static fn (PromptStep $step): array => [$step->value => self::promptStep($step)])
            ->all();
    }

    /** @param iterable<LlmPurpose> $purposes
     * @return array<string, string>
     */
    public static function purposeOptions(iterable $purposes): array
    {
        return collect(array_values(iterator_to_array($purposes)))
            ->mapWithKeys(static fn (LlmPurpose $purpose): array => [$purpose->value => self::purpose($purpose)])
            ->all();
    }
}
