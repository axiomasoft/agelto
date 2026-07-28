<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Эталон скрининга ОДНОГО эталона корпуса (P5.4, D5, §8.5): `class=neg` → вердикт из реестра
 * (`Fixture::doc_type`), `class=est` → `estimate` (D5). Форма — дословно {@see ScreeningResult}:
 * `confidence` эталона всегда `1.0` (в сравнении не участвует, §8.5), `description`/`ignore_reason`
 * берутся из `Fixture::notes` (единственный текстовый носитель причины включения в корпус,
 * `research/02-fixture-shortlist.md`) — не сочиняются. Негатив без `doc_type` в реестре —
 * ОТКЛОНЕНИЕ (Code Guidance: реестр обязан его нести, расширять `AiDocumentType` под корпус
 * запрещено — это решение владельца, не самодеятельность здесь).
 */
final readonly class ScreeningGoldBuilder
{
    /**
     * @return array{action: 'built'|'rejected', screening: array<string, mixed>|null, reason: string|null}
     */
    public function build(Fixture $fixture): array
    {
        if ($fixture->class === FixtureClass::Negative) {
            if ($fixture->doc_type === null) {
                return [
                    'action' => 'rejected',
                    'screening' => null,
                    'reason' => 'негатив без doc_type в реестре (D5) — заполняется P1.3/P2, не этой командой',
                ];
            }

            $payload = [
                'document_type' => $fixture->doc_type->value,
                'confidence' => 1.0,
                'description' => $fixture->notes,
                'page_count_estimate' => null,
                'ignore_reason' => $fixture->notes,
            ];
        } else {
            $payload = [
                'document_type' => AiDocumentType::Estimate->value,
                'confidence' => 1.0,
                'description' => $fixture->notes,
                'page_count_estimate' => null,
                'ignore_reason' => null,
            ];
        }

        try {
            ScreeningResult::validateAndCreate($payload);
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $field => $fieldMessages) {
                $messages[] = "{$field}: ".implode('; ', $fieldMessages);
            }

            return ['action' => 'rejected', 'screening' => null, 'reason' => 'ScreeningResult: '.implode('; ', $messages)];
        } catch (CannotCreateData $e) {
            return ['action' => 'rejected', 'screening' => null, 'reason' => "ScreeningResult: {$e->getMessage()}"];
        }

        // `tags` НЕ пишется ключом: Laravel `required` отклоняет ПРИСУТСТВУЮЩИЙ пустой массив
        // (в отличие от отсутствующего — там срабатывает дефолт `[]`), а `tags` эталона всегда
        // пуст (v1-метки не предмет эталона) — присутствие ключа сделало бы файл невалидируемым
        // повторно тем же `ScreeningResult::validateAndCreate()`.
        return ['action' => 'built', 'screening' => $payload, 'reason' => null];
    }
}
