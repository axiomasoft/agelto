<?php

declare(strict_types=1);

namespace App\Dto\Llm\Reporting;

/**
 * Ответ источника расхода: строки + ПОДПИСЬ как поле, а не
 * отдельный вызов — цифру без подписи получить нельзя. `beyondHorizon` — явный признак «часть
 * запрошенного окна лежит за пределом хранения» (§4): строки в пересечении окна с горизонтом
 * при этом возвращаются как есть, а молча пустая серия запрещена — её невозможно отличить от нуля.
 */
final readonly class UsageReport
{
    /**
     * @param  list<UsageRow>  $rows
     */
    public function __construct(
        public array $rows,
        public UsageSignature $signature,
        public bool $beyondHorizon,
    ) {
        if ($beyondHorizon && $signature->horizonFrom === null) {
            throw new \InvalidArgumentException('An unbounded source has no retention cutoff.');
        }
    }
}
