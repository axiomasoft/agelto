<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Единственная в фазе P5 точка валидации формы gold §8.2 (Code Guidance P5.1: «форма §8.2
 * проверяется ТОЛЬКО GoldValidator, второй приём валидации в фазе запрещён»). Приём — ДОСЛОВНО
 * `DatasetImportCommand::validateGold()` (`app/Console/Commands/Ai/DatasetImportCommand.php:110-
 * 174`, P2.2-контракт): `PassportResponse::validateAndCreate(Arr::except($estimate, ['rows']))` +
 * `EstimateRow::validateAndCreate()` на каждую строку — совпадение с приёмом импорта намеренно
 * (эталон и предсказание сравниваются без отдельного маппера, docs/specs/v2/
 * 08-estimate-extraction.md §8.2), унификация чужой команды — вне скоупа этого item'а.
 */
final readonly class GoldValidator
{
    /**
     * @param  array<string, mixed>  $gold
     * @return list<string> Адреса `estimates.<i>.<поле>` / `estimates.<i>.rows.<j>.<поле>`; пустой
     *                      список = валидно.
     */
    public function validate(array $gold): array
    {
        if (! is_array($gold['estimates'] ?? null)) {
            return ['ожидается объект вида {"estimates": [...]} (docs/specs/v2/08-estimate-extraction.md §8.2)'];
        }

        $errors = [];

        foreach (array_values($gold['estimates']) as $index => $estimateJson) {
            if (! is_array($estimateJson)) {
                $errors[] = "estimates.{$index}: ожидается объект";

                continue;
            }

            $rowsJson = $estimateJson['rows'] ?? [];

            try {
                PassportResponse::validateAndCreate(Arr::except($this->stringKeyedArray($estimateJson), ['rows']));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[] = "estimates.{$index}.{$field}: ".$this->joinMessages($messages);
                }
            } catch (CannotCreateData $e) {
                $errors[] = "estimates.{$index}: {$e->getMessage()}";
            }

            $rows = is_array($rowsJson) ? array_values($rowsJson) : [$rowsJson];

            foreach ($rows as $rowIndex => $rowJson) {
                if (! is_array($rowJson)) {
                    $errors[] = "estimates.{$index}.rows.{$rowIndex}: ожидается объект";

                    continue;
                }

                try {
                    EstimateRow::validateAndCreate($this->stringKeyedArray($rowJson));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[] = "estimates.{$index}.rows.{$rowIndex}.{$field}: ".$this->joinMessages($messages);
                    }
                } catch (CannotCreateData $e) {
                    $errors[] = "estimates.{$index}.rows.{$rowIndex}: {$e->getMessage()}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $gold
     *
     * @throws ValidationException Список ошибок под ключом `gold`.
     */
    public function assertValid(array $gold): void
    {
        $errors = $this->validate($gold);

        if ($errors !== []) {
            throw ValidationException::withMessages(['gold' => $errors]);
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function joinMessages(mixed $messages): string
    {
        if (! is_array($messages)) {
            return is_scalar($messages) ? (string) $messages : 'неизвестная ошибка валидации';
        }

        return implode('; ', array_filter($messages, is_string(...)));
    }
}
