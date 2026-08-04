<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Эталон скрининга ОДНОГО эталона корпуса (P5.4, D5, §8.5): вердикт — `Fixture::document_type`
 * для ЛЮБОГО класса эталона (§6.1 root/document-type-axis.md, D90 — колонка `NOT NULL` и
 * заполнена и для позитивов, ветвление по классу для ВЕРДИКТА больше не нужно). Форма — дословно
 * {@see ScreeningResult}: `confidence` эталона всегда `1.0` (в сравнении не участвует, §8.5),
 * `description` — из `Fixture::notes` (единственный текстовый носитель причины включения в
 * корпус, `research/02-fixture-shortlist.md`); `ignore_reason` заполняется только у негативов
 * (`profileClass() === FixtureClass::Negative`) — у позитива объяснять нечего, документ и есть
 * ожидаемый тип.
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
            'description' => $fixture->notes,
            'page_count_estimate' => null,
            'ignore_reason' => $fixture->profileClass() === FixtureClass::Negative ? $fixture->notes : null,
        ];

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
