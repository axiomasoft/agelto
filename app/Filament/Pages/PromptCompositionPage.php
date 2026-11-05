<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Support\LlmPresentation;
use App\Models\Llm\Prompt;
use App\Repositories\Llm\PromptReadRepository;
use App\Services\Llm\Dto\ComposedPrompt;
use App\Services\Llm\PromptComposer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Показывает только текущую RULES-часть `PromptComposer`: schema-блок приходит от места вызова,
 * поэтому этот экран не является и не пытается пересобрать финальный payload LLM-вызова.
 *
 * URL защищён явно через PromptResource::canViewAny(): скрытие navigation-пункта не защищает
 * кастомную Filament-страницу, а право страницы само по себе не заменяет PromptPermission::ViewAny.
 */
final class PromptCompositionPage extends Page
{
    protected static ?string $slug = 'prompt-composition';

    protected static string|\UnitEnum|null $navigationGroup = 'Настройка AI';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Сборка инструкции';

    protected string $view = 'filament.pages.prompt-composition-page';

    public string $documentType = DocumentTypeScope::Any->value;

    public string $step = 'global';

    public static function canAccess(): bool
    {
        return PromptResource::canViewAny();
    }

    public function getTitle(): string
    {
        return 'Сборка инструкции';
    }

    /** @return array<string, string> */
    public function documentTypeOptions(): array
    {
        return [
            DocumentTypeScope::Any->value => 'Любой тип документа',
            ...LlmPresentation::documentTypeOptions(),
        ];
    }

    /** @return array<string, string> */
    public function stepOptions(): array
    {
        return LlmPresentation::promptStepOptions(
            steps: app(DocumentAnalyzerRegistry::class)->promptStepsFor($this->selectedDocumentType()),
        );
    }

    public function updatedDocumentType(): void
    {
        $this->step = $this->defaultStepFor($this->selectedDocumentType());
    }

    public function selectedPromptStep(): PromptStep
    {
        $documentType = $this->selectedDocumentType();
        $step = $this->step !== '' && array_key_exists($this->step, $this->stepOptions())
            ? $this->step
            : $this->defaultStepFor($documentType);

        return app(DocumentAnalyzerRegistry::class)->promptStepFor($documentType, $step);
    }

    public function composedPrompt(): ComposedPrompt
    {
        return app(PromptComposer::class)->compose(step: $this->selectedPromptStep());
    }

    /** @return Collection<int, Prompt> */
    public function participatingPrompts(): Collection
    {
        $step = $this->selectedPromptStep();

        return app(PromptReadRepository::class)->activeForSteps(
            steps: $step->compositionChain(),
            documentType: $step->documentTypeScope(),
        );
    }

    private function defaultStepFor(AiDocumentType|DocumentTypeScope $documentType): string
    {
        $options = LlmPresentation::promptStepOptions(
            steps: app(DocumentAnalyzerRegistry::class)->promptStepsFor($documentType),
        );

        return array_key_first($options) ?? 'global';
    }

    private function selectedDocumentType(): AiDocumentType|DocumentTypeScope
    {
        return AiDocumentType::tryFrom($this->documentType) ?? DocumentTypeScope::Any;
    }
}
