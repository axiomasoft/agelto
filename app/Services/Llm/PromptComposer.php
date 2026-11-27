<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Dto\Llm\Prompt\ComposedPrompt;
use App\Enums\Llm\PromptStep;
use App\Models\Llm\Prompt;
use App\Repositories\Llm\PromptReadRepository;
use App\Services\Llm\Schema\PromptContextGenerator;

/**
 * Собирает системную часть шага (ТЗ 04 §3.2, порядок v1 — `Old/app/Repositories/Prompt/PromptRuleRepository.php`):
 * правила из `llm_prompts` (global → step-global → step, `priority` ASC внутри) + блок схем
 * (`PromptContextGenerator`), Responses-обёртки всегда последними (канон v1, внутри
 * генератора). Композиция детерминирована — это префикс implicit-кэша всех провайдеров
 * (research/E §1).
 */
final readonly class PromptComposer
{
    public function __construct(
        private PromptReadRepository $prompts,
        private PromptContextGenerator $schemas,
    ) {}

    /**
     * @param  list<class-string>  $schemaClasses  Data-классы шага для блока схем.
     */
    public function compose(PromptStep $step, array $schemaClasses = []): ComposedPrompt
    {
        $prompts = $this->prompts->activeForSteps($step->compositionChain(), $step->documentTypeScope());

        $parts = [];

        $rulesContent = $prompts->pluck('content')->implode("\n\n---\n\n");
        if ($rulesContent !== '') {
            $parts[] = $rulesContent;
        }

        $schemaBlock = $schemaClasses === [] ? '' : $this->schemas->generate($schemaClasses);
        if ($schemaBlock !== '') {
            $parts[] = $schemaBlock;
        }

        $systemPart = implode("\n\n---\n\n", $parts);

        return new ComposedPrompt(
            systemPart: $systemPart,
            rulesHash: $this->schemas->rulesHash($systemPart),
            promptVersionSet: $prompts
                ->mapWithKeys(fn (Prompt $prompt): array => [$prompt->key => $prompt->version])
                ->all(),
        );
    }
}
