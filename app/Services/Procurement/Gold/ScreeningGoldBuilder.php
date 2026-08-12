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
 * Эталон скрининга ОДНОГО эталона корпуса (P5.4, D5, §8.5): вердикт — `Fixture::document_type`
 * для ЛЮБОГО класса эталона (§6.1 root/document-type-axis.md, D90 — колонка `NOT NULL` и
 * заполнена и для позитивов, ветвление по классу для ВЕРДИКТА больше не нужно). Форма — дословно
 * {@see ScreeningResult}: `confidence` эталона всегда `1.0` (в сравнении не участвует, §8.5),
 * `summary` — заметка корпуса либо title, когда заметка ещё не заполнена; `classification_note`
 * заполняется только у негативов и остаётся неавторитетным пояснением, не backend-решением.
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
                $messages[] = "{$field}: ".implode('; ', $fieldMessages);
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
