<?php

declare(strict_types=1);

namespace App\Services\Llm\Validation;

use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Enums\Procurement\FinishReason;
use App\Services\Llm\Dto\LlmStructuredResponse;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Throwable;

/**
 * Валидационный каскад structured-ответа (ТЗ 04 §2/§6, research/E §2/§6): finishReason-гейт →
 * json_validate()/json_decode(JSON_THROW_ON_ERROR) → гидрация Data-класса → структурные проверки.
 * Никакого «repair» невалидного JSON (дописывание скобок) — гейт finishReason обязан ловить
 * обрезание раньше; семантические проблемы уходят на re-ask (`ReaskPolicy`).
 */
final readonly class StructuredResponseValidator
{
    /**
     * @param  class-string<Data>  $dataClass
     */
    public function validate(LlmStructuredResponse $response, string $dataClass): ValidationOutcome
    {
        if ($response->finishReason === FinishReason::MaxTokens) {
            return ValidationOutcome::retryable(
                'MAX_TOKENS: ответ обрезан провайдером до валидного JSON — уменьшить окно/диапазон запроса и повторить (наверх, оркестратору).',
            );
        }

        if ($response->finishReason !== FinishReason::Stop) {
            return ValidationOutcome::fatal(
                "Генерация остановлена по причине «{$response->finishReason->value}» (не stop) — неопределённый исход, требует эскалации маршрутом.",
            );
        }

        if (! json_validate($response->raw)) {
            return ValidationOutcome::retryable('Ответ не является синтаксически валидным JSON.');
        }

        try {
            $decoded = json_decode($response->raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ValidationOutcome::retryable("Ошибка декодирования JSON: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            return ValidationOutcome::retryable('Декодированный JSON не является объектом/массивом верхнего уровня.');
        }

        try {
            $data = $dataClass::validateAndCreate($decoded);
        } catch (ValidationException|CannotCreateData $e) {
            return ValidationOutcome::retryable("Ответ не прошёл валидацию схемы: {$e->getMessage()}");
        } catch (Throwable $e) {
            return ValidationOutcome::retryable("Гидрация ответа в {$dataClass} завершилась ошибкой: {$e->getMessage()}");
        }

        $structuralError = $this->structuralError($data);

        if ($structuralError !== null) {
            return ValidationOutcome::retryable($structuralError);
        }

        return ValidationOutcome::ok($data);
    }

    /**
     * Доменные проверки на этом уровне — только структурные (row_index монотонен внутри чанка,
     * без дублей); арифметическая сверка сумм — доменный уровень P6.2, не этот сервис.
     */
    private function structuralError(Data $data): ?string
    {
        if (! $data instanceof RowsChunkResponse) {
            return null;
        }

        $seenRowIndexes = [];
        $previousRowIndex = null;

        foreach ($data->rows as $row) {
            if (isset($seenRowIndexes[$row->row_index])) {
                return "Дубликат row_index={$row->row_index} в чанке.";
            }

            $seenRowIndexes[$row->row_index] = true;

            if ($previousRowIndex !== null && $row->row_index <= $previousRowIndex) {
                return "row_index={$row->row_index} нарушает монотонность (предыдущий row_index={$previousRowIndex}).";
            }

            $previousRowIndex = $row->row_index;
        }

        return null;
    }
}
