<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;

/**
 * Минимальный контракт анализатора типа для ядра. Персистер, ститчер, валидатор, метрика
 * и прочие внутренние части вызываются собственным кодом анализатора и не расширяют интерфейс.
 * Тип задаётся ключом реестра, поэтому отдельный documentType() не требуется.
 */
interface DocumentAnalyzer
{
    /**
     * Есть ли у типа стадия извлечения. Ядро спрашивает ДО и НЕЗАВИСИМО от диспатча: этим ответом
     * подписывается событие скрининга и терминируется прогон на ветке «извлечения не будет».
     */
    public function hasExtraction(): bool;

    /**
     * Отправляет extraction job своего типа. Управление execution и checkpoint остаётся
     * внутри анализатора. Если hasExtraction()=false, вызов является ошибкой caller
     * и должен завершиться LogicException.
     */
    public function dispatchExtraction(int $documentId): void;

    /**
     * Только СОБСТВЕННЫЕ шаги композиции типа: кросс-документные принадлежат ядру и здесь не
     * перечисляются.
     *
     * @return list<PromptStep>
     */
    public function promptSteps(): array;

    /**
     * Только СОБСТВЕННЫЕ назначения вызовов типа (та же граница, что у `promptSteps()`).
     *
     * @return list<LlmPurpose>
     */
    public function purposes(): array;

    /**
     * Сводка результата для витрины прогонов — данные, не разметка.
     */
    public function summarize(Analysis $analysis): AnalysisSummary;
}
