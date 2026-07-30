<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;

/**
 * Что тип документа обязан объявить о себе ядру (§1 контракта P2.1, D14/D16/D27).
 *
 * В интерфейс входит РОВНО то, что вызывает ЯДРО: персистер, ститчер, валидатор, метрика, промты
 * и оркестратор конкретного типа вызываются ЕГО ЖЕ кодом внутри его каталога
 * (`**\Analyzers\<Type>\**`) и сюда не выносятся. Широкий интерфейс здесь дороже узкого: его
 * реализует КАЖДЫЙ будущий тип, то есть цена ошибки умножается на число типов.
 *
 * Метода `documentType()` НЕТ намеренно: ключ реестра и есть тип, спрашивать анализатор о том,
 * что ядро уже знает, — лишняя поверхность.
 */
interface DocumentAnalyzer
{
    /**
     * Есть ли у типа стадия извлечения. Ядро спрашивает ДО и НЕЗАВИСИМО от диспатча: этим ответом
     * подписывается событие скрининга и терминируется прогон на ветке «извлечения не будет».
     */
    public function hasExtraction(): bool;

    /**
     * Диспатчит СВОЙ job извлечения. Контур checkpoint-requeue / lease /
     * `ShouldBeUniqueUntilProcessing` (D28/D39) остаётся ВНУТРИ анализатора и ядру не виден.
     *
     * Вызов на анализаторе с `hasExtraction() === false` — ошибка ядра, а не штатная ветка:
     * реализация обязана бросить `LogicException`.
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
     * Сводка результата для витрины прогонов (D27) — данные, не разметка.
     */
    public function summarize(Analysis $analysis): AnalysisSummary;
}
