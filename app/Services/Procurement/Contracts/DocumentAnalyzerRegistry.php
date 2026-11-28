<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Llm\DocumentScope;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Llm\Contracts\PromptScopeRegistry;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Реестр type → analyzer из config('ai.analyzers'). Значения — имена классов,
 * совместимые с config:cache; экземпляры создаёт контейнер с обычной DI.
 * На первом resolve проверяет AiDocumentType кроме Unknown, реализацию DocumentAnalyzer
 * и отсутствие пересечений prompt steps/purposes с CorePromptStep/CoreLlmPurpose.
 * Значения steps/purposes получает через интерфейс, без импорта enum конкретного анализатора.
 * Решение по неизвестному типу остаётся в screening, а не в registry.
 */
final readonly class DocumentAnalyzerRegistry implements PromptScopeRegistry
{
    /** @var array<string, DocumentAnalyzer> */
    private array $analyzers;

    public function __construct(Container $container)
    {
        $analyzers = [];

        /** @var array<array-key, mixed> $registrations */
        $registrations = (array) config('ai.analyzers', []);

        $coreStepValues = array_map(fn (CorePromptStep $case): string => $case->value, CorePromptStep::cases());
        $corePurposeValues = array_map(fn (CoreLlmPurpose $case): string => $case->value, CoreLlmPurpose::cases());

        foreach ($registrations as $type => $class) {
            $documentType = AiDocumentType::tryFrom((string) $type);

            if ($documentType === null) {
                throw new InvalidArgumentException("config('ai.analyzers'): «{$type}» не является значением AiDocumentType.");
            }

            if ($documentType === AiDocumentType::Unknown) {
                throw new InvalidArgumentException("config('ai.analyzers'): тип «{$documentType->value}» не имеет анализатора по контракту.");
            }

            if (! is_string($class)) {
                throw new InvalidArgumentException("config('ai.analyzers'): значение для «{$documentType->value}» обязано быть именем класса строкой.");
            }

            $analyzer = $container->make($class);

            if (! $analyzer instanceof DocumentAnalyzer) {
                throw new InvalidArgumentException("config('ai.analyzers'): {$class} не реализует ".DocumentAnalyzer::class.'.');
            }

            $ownStepValues = array_map(fn (PromptStep $step): string => $step->value, $analyzer->promptSteps());
            $overlappingSteps = array_intersect($ownStepValues, $coreStepValues);

            if ($overlappingSteps !== []) {
                throw new InvalidArgumentException(
                    "config('ai.analyzers'): анализатор «{$class}» объявляет ядерные значения step: ".implode(', ', $overlappingSteps).'.',
                );
            }

            $ownPurposeValues = array_map(fn (LlmPurpose $purpose): string => $purpose->value, $analyzer->purposes());
            $overlappingPurposes = array_intersect($ownPurposeValues, $corePurposeValues);

            if ($overlappingPurposes !== []) {
                throw new InvalidArgumentException(
                    "config('ai.analyzers'): анализатор «{$class}» объявляет ядерные значения purpose: ".implode(', ', $overlappingPurposes).'.',
                );
            }

            $analyzers[$documentType->value] = $analyzer;
        }

        $this->analyzers = $analyzers;
    }

    /**
     * Анализатор типа или `null`, если типу анализатор не назначен (в том числе для
     * `AiDocumentType::Unknown`, который зарегистрировать нельзя по построению).
     */
    public function for(AiDocumentType $documentType): ?DocumentAnalyzer
    {
        return $this->analyzers[$documentType->value] ?? null;
    }

    /**
     * Допустимые шаги композиции для типа: ядерные ∪ собственные (для `DocumentTypeScope::Any`
     * или типа без анализатора — только ядерные).
     *
     * @return list<PromptStep>
     */
    public function promptStepsFor(AiDocumentType|DocumentTypeScope $documentType): array
    {
        $core = CorePromptStep::cases();

        if ($documentType instanceof DocumentTypeScope) {
            return $core;
        }

        $analyzer = $this->analyzers[$documentType->value] ?? null;

        return $analyzer === null ? $core : [...$core, ...$analyzer->promptSteps()];
    }

    /**
     * Резолв «строка → носитель шага» для типа — единственное МЕСТО, где значение `step`
     * проверяется на границе записи. Бросает на значении вне множества типа —
     * fail-fast, та же семантика, что была у `PromptStep::from()` до расщепления.
     */
    public function documentType(string $value): DocumentScope
    {
        return AiDocumentType::tryFrom($value) ?? ($value === DocumentTypeScope::Any->value ? DocumentTypeScope::Any
            : throw new \RuntimeException('Unknown document_type in prompt canon.'));
    }

    public function promptStepFor(DocumentScope $documentType, string $value): PromptStep
    {
        if (! $documentType instanceof AiDocumentType && ! $documentType instanceof DocumentTypeScope) {
            throw new InvalidArgumentException('Unregistered prompt document scope.');
        }

        foreach ($this->promptStepsFor($documentType) as $step) {
            if ($step->value === $value) {
                return $step;
            }
        }

        throw new InvalidArgumentException("Реестр: значение step «{$value}» недопустимо для document_type «{$documentType->value}».");
    }

    /**
     * Допустимые назначения вызова для типа — та же граница, что `promptStepsFor()`.
     *
     * @return list<LlmPurpose>
     */
    public function purposesFor(AiDocumentType|DocumentTypeScope $documentType): array
    {
        $core = CoreLlmPurpose::cases();

        if ($documentType instanceof DocumentTypeScope) {
            return $core;
        }

        $analyzer = $this->analyzers[$documentType->value] ?? null;

        return $analyzer === null ? $core : [...$core, ...$analyzer->purposes()];
    }

    /**
     * Резолв «строка → носитель назначения» для типа — та же fail-fast
     * семантика, что `promptStepFor()`.
     */
    public function purposeFor(AiDocumentType|DocumentTypeScope $documentType, string $value): LlmPurpose
    {
        foreach ($this->purposesFor($documentType) as $purpose) {
            if ($purpose->value === $value) {
                return $purpose;
            }
        }

        throw new InvalidArgumentException("Реестр: значение purpose «{$value}» недопустимо для document_type «{$documentType->value}».");
    }
}
