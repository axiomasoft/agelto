<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Эталон скрининга берёт вердикт из Fixture::document_type для любого класса корпуса.
 * Форма — ScreeningResult; confidence=1.0 не участвует в сравнении. Summary берётся
 * из заметки или title. Classification_note у негативов — пояснение, не backend-решение.
 */
final readonly class ScreeningGoldBuilder
{
    /**
     * @return array{action: 'built'|'rejected', screening: array<string, mixed>|null, reason: string|null}
     */
    public function build(Fixture $fixture): array
    {
        $payload = [
            'document_type' => $fixture->document_type->value,
            'confidence' => 1.0,
            'summary' => $fixture->notes ?? $fixture->title,
            'estimate_cardinality' => $this->estimateCardinality($fixture)->value,
            'tags' => [],
            'classification_note' => $fixture->profileClass() === FixtureClass::Negative ? $fixture->notes : null,
        ];

        try {
            ScreeningResult::validateAndCreate($payload);
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $field => $fieldMessages) {
                if (! is_array($fieldMessages)) {
                    continue;
                }

                $messages[] = "{$field}: ".implode('; ', array_filter($fieldMessages, is_string(...)));
            }

            return ['action' => 'rejected', 'screening' => null, 'reason' => 'ScreeningResult: '.implode('; ', $messages)];
        } catch (Throwable $e) {
            return ['action' => 'rejected', 'screening' => null, 'reason' => "ScreeningResult: {$e->getMessage()}"];
        }

        return ['action' => 'built', 'screening' => $payload, 'reason' => null];
    }

    private function estimateCardinality(Fixture $fixture): EstimateCardinality
    {
        if ($fixture->document_type !== AiDocumentType::Estimate) {
            return EstimateCardinality::None;
        }

        return match ($fixture->profileExpectedEstimates()) {
            null => EstimateCardinality::Unknown,
            1 => EstimateCardinality::Single,
            default => EstimateCardinality::Multiple,
        };
    }
}
