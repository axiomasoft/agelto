<?php

declare(strict_types=1);

namespace App\Services\Llm\Validation;

use App\Dto\Llm\Execution\ResolvedRoute;
use App\Dto\Llm\Prompt\JsonSchema;
use App\Dto\Llm\Prompt\LlmPrompt;
use App\Enums\Llm\FinishReason;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Политика ретраев structured-вызова (ТЗ 04 §2/§6, research/E §6 — «repair — синтаксис, re-ask —
 * семантика, escalate — неопределённость»): до 2 re-ask с текстом ошибки валидатора и прошлым
 * выводом на ТОМ ЖЕ маршруте, затем эскалация на следующий по `priority` маршрут
 * (`LlmRouteResolver::candidatesFor`); маршруты исчерпаны — итоговый `retryable`/`fatal` исход
 * уходит в error-блок вызывающего кода. MAX_TOKENS не re-ask'ается и не эскалируется маршрутом —
 * бабблится немедленно наверх (оркестратору, который умеет уменьшать окно/диапазон).
 */
final readonly class ReaskPolicy
{
    public const int MAX_REASKS = 2;

    public function __construct(
        private StructuredResponseValidator $validator,
    ) {}

    /**
     * @param  class-string<Data>  $dataClass
     * @param  Collection<int, ResolvedRoute>  $candidates  Маршруты `priority` ASC — первый
     *                                                      пробуется первым, следующие — эскалация при исчерпании re-ask на предыдущем.
     */
    public function run(LlmPrompt $prompt, JsonSchema $schema, string $dataClass, Collection $candidates): ValidationOutcome
    {
        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException('ReaskPolicy: список маршрутов пуст — нечего резолвить.');
        }

        $lastOutcome = null;

        foreach ($candidates as $route) {
            $currentPrompt = $prompt;
            $reasks = 0;

            while (true) {
                $response = $route->provider->completeStructured($currentPrompt, $schema, $route->options);
                $outcome = $this->validator->validate($response, $dataClass);

                if ($outcome->isOk()) {
                    return $outcome;
                }

                if ($response->finishReason === FinishReason::MaxTokens) {
                    return $outcome;
                }

                $lastOutcome = $outcome;

                if ($outcome->isRetryable() && $reasks < self::MAX_REASKS) {
                    $reasks++;
                    $currentPrompt = $this->withReaskMessage($currentPrompt, (string) $outcome->reason, $response->raw);

                    continue;
                }

                break;
            }
        }

        return $lastOutcome ?? ValidationOutcome::fatal('ReaskPolicy: ни один маршрут не вернул ответ.');
    }

    private function withReaskMessage(LlmPrompt $prompt, string $reason, string $previousRaw): LlmPrompt
    {
        $message = "Предыдущий ответ не прошёл валидацию: {$reason}. Верни исправленный JSON той же схемы, ничего не поясняй.\n\nПредыдущий ответ:\n{$previousRaw}";

        return new LlmPrompt(
            systemPart: $prompt->systemPart,
            userParts: [...$prompt->userParts, $message],
            fileRefs: $prompt->fileRefs,
        );
    }
}
