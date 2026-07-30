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
                PassportResponse::validateAndCreate(Arr::except($estimateJson, ['rows']));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[] = "estimates.{$index}.{$field}: ".implode('; ', $messages);
                }
            } catch (CannotCreateData $e) {
                $errors[] = "estimates.{$index}: {$e->getMessage()}";
            }

            foreach (array_values((array) $rowsJson) as $rowIndex => $rowJson) {
                try {
                    EstimateRow::validateAndCreate((array) $rowJson);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[] = "estimates.{$index}.rows.{$rowIndex}.{$field}: ".implode('; ', $messages);
                    }
                } catch (CannotCreateData $e) {
                    $errors[] = "estimates.{$index}.rows.{$rowIndex}: {$e->getMessage()}";
                }
            }
        }

        return $errors;
    }

    /**
     * @throws ValidationException Список ошибок под ключом `gold`.
     */
    public function assertValid(array $gold): void
    {
        $errors = $this->validate($gold);

        if ($errors !== []) {
            throw ValidationException::withMessages(['gold' => $errors]);
        }
    }
}
