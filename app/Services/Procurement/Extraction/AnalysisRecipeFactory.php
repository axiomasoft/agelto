<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Llm\Requests\TaskManifest;
use App\Dto\Llm\Requests\TaskSnapshot;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\CoverageStrategy;
use App\Dto\Procurement\Ai\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\PageExtractionOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\ScreeningPolicySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Procurement\Screening\ScreeningContract;

/** Resolve before transactions. Every purpose's schema and prompt is sealed in one immutable manifest. */
final readonly class AnalysisRecipeFactory
{
    public TaskManifest $tasks;

    public function __construct()
    {
        $screening = (new ScreeningContract)->task($this->prompt('screening-v1.txt'));
        $discovery = new StructuredTask(PageDiscoveryOutput::class, $this->prompt('page-discovery-v1.txt'), TaskPurpose::Discovery,
            new DiscoverySemanticValidator(new PageRange(0, 1)));
        $rows = new StructuredTask(PageExtractionOutput::class, $this->prompt('page-extraction-v1.txt'), TaskPurpose::Rows,
            new ExtractionSemanticValidator(new PageRange(0, 1), 1, ['page:0' => 0]));
        $this->tasks = new TaskManifest(array_map(static fn (StructuredTask $task): TaskSnapshot => new TaskSnapshot($task->purpose, $task->prompt, $task->schema), [$screening, $discovery, $rows]));
        foreach ($this->tasks->tasks as $task) {
            (new ProviderSchemaCompiler)->compile($task->schema);
        }
    }

    private function prompt(string $name): PromptSnapshot
    {
        $text = file_get_contents(resource_path('ai/prompts/contracts/'.$name));
        if ($text === false) {
            throw new \LogicException('Missing canonical analysis prompt.');
        }

        return new PromptSnapshot($text, 1);
    }

    public function make(ContentHash $preparation, PinnedLlmContext $context, CoverageStrategy $coverage,
        string $currency = 'RUB', string $locale = 'ru-RU', ?ScreeningPolicySnapshot $screening = null): AnalysisRecipe
    {
        return new AnalysisRecipe($preparation, 'estimate-pages/1', $this->tasks, $context, $coverage, $currency, $locale,
            $screening ?? new ScreeningPolicySnapshot);
    }
}
