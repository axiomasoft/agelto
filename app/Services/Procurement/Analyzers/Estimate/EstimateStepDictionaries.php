<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;

/**
 * Словари шага анализатора сметы — классы-носители `#[Alias]`, которые модель обязана видеть в
 * системной части, но до которых обход графа типов от обёртки ответа шага НЕ достаёт.
 *
 * Объявление ОДНО на тип и читается КАЖДЫМ потребителем шага: у `extraction:rows` их трое —
 * построчный источник чанков, оконный и экспорт датасета. Захардкоженный список в каждом из них
 * разъехался бы молча, и датасет обучался бы на промте, отличном от боевого.
 *
 * Достижимое обходом здесь НЕ перечисляется: `ResourceType` приезжает цепочкой
 * `RowsChunkResponse → EstimateRow → RowResource`, и второе объявление сделало бы его вторым
 * носителем одного факта. `EstimateHeaderMap` не приезжает ничем — он «НЕ часть JSON-ответа LLM
 * ни одного шага» (собственный докблок класса), поэтому объявляется явно.
 */
final readonly class EstimateStepDictionaries
{
    /**
     * @return list<class-string>
     */
    public function forStep(PromptStep $step): array
    {
        return match ($step) {
            EstimatePromptStep::ExtractionRows => [EstimateHeaderMap::class],
            default => [],
        };
    }
}
