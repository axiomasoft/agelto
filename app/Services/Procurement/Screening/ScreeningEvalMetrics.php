<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\AiDocumentType;

/**
 * Классификационная метрика скрининга (P5.6, D5/D44) — независима от `App\Services\Procurement\
 * Extraction\EvalMetrics` (field-level F1, §8.4): скрининг сравнивает ОДНО поле, `document_type`
 * (§8.5). Ключевое число — `false_positive_rate` (D5: ложное срабатывание «не смета → смета»
 * дороже пропуска, документ уходит в дорогое извлечение впустую), не средняя `accuracy` — при
 * малом числе негативов accuracy маскирует именно этот класс ошибок.
 */
final readonly class ScreeningEvalMetrics
{
    /**
     * @param  array<string, mixed>  $gold
     * @return array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool}
     */
    public function compare(array $gold, ScreeningResult $prediction): array
    {
        $goldType = (string) ($gold['document_type'] ?? '');
        $predictedType = $prediction->document_type->value;

        return [
            'gold' => $goldType,
            'predicted' => $predictedType,
            'match' => $goldType === $predictedType,
            'gold_is_estimate' => $goldType === AiDocumentType::Estimate->value,
            'predicted_is_estimate' => $predictedType === AiDocumentType::Estimate->value,
        ];
    }

    /**
     * @param  list<array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool}>  $comparisons
     * @return array{
     *     total: int, accuracy: float|null,
     *     confusion: array<string, array<string, int>>,
     *     false_positive_rate: float|null, false_negative_rate: float|null,
     * }
     */
    public function aggregate(array $comparisons): array
    {
        $total = count($comparisons);

        if ($total === 0) {
            return ['total' => 0, 'accuracy' => null, 'confusion' => [], 'false_positive_rate' => null, 'false_negative_rate' => null];
        }

        $confusion = [];

        foreach ($comparisons as $comparison) {
            $confusion[$comparison['gold']][$comparison['predicted']] ??= 0;
            $confusion[$comparison['gold']][$comparison['predicted']]++;
        }

        $negatives = array_values(array_filter($comparisons, static fn (array $c): bool => ! $c['gold_is_estimate']));
        $positives = array_values(array_filter($comparisons, static fn (array $c): bool => $c['gold_is_estimate']));
        $correct = count(array_filter($comparisons, static fn (array $c): bool => $c['match']));

        return [
            'total' => $total,
            'accuracy' => round($correct / $total, 4),
            'confusion' => $confusion,
            'false_positive_rate' => $negatives === [] ? null : round(
                count(array_filter($negatives, static fn (array $c): bool => $c['predicted_is_estimate'])) / count($negatives),
                4,
            ),
            'false_negative_rate' => $positives === [] ? null : round(
                count(array_filter($positives, static fn (array $c): bool => ! $c['predicted_is_estimate'])) / count($positives),
                4,
            ),
        ];
    }
}
