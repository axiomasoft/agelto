<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Llm\LlmPurpose;
use App\Models\Llm\LlmCall;
use App\Repositories\Llm\LlmCallStoreRepository;
use App\Services\Llm\Dto\LlmUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Запись КАЖДОГО вызова LLM в `llm_calls` (ТЗ 04 §6) — включая неуспех
 * (`success=false`, `error` заполнен, `usage=null` допустим для отказов до получения ответа).
 * Сырые request/response — НЕ inline, ссылки на object storage (`config('crawler.raw_disk')`,
 * тот же диск, что документы/сырой HTML — единый канон `*_ref`). Вызывается оркестрирующим
 * кодом ПОСЛЕ вызова `LlmProvider` (P4–P6) — сам адаптер (`PrismGeminiProvider`) домена
 * `analysis_id`/`purpose`/`prompt_version_set` не знает (это контекст пайплайна, не транспорта),
 * поэтому контракт `LlmProvider` (P3.1) recorder'ом не расширяется.
 */
final readonly class LlmCallRecorder
{
    public function __construct(
        private LlmCallStoreRepository $storeRepository,
    ) {}

    /**
     * @param  array<string, string>  $promptVersionSet  key => version участвовавших промптов
     */
    public function record(
        ?int $analysisId,
        LlmPurpose $purpose,
        string $provider,
        string $model,
        array $promptVersionSet,
        ?string $cacheId,
        string $requestPayload,
        ?string $responsePayload,
        ?LlmUsage $usage,
        bool $success,
        ?string $error,
        int $durationMs,
        CarbonImmutable $calledAt,
    ): LlmCall {
        $llmCall = $this->storeRepository->create([
            'analysis_id' => $analysisId,
            'purpose' => $purpose->value,
            'provider' => $provider,
            'model' => $model,
            'prompt_version_set' => $promptVersionSet,
            'cache_id' => $cacheId,
            'request_ref' => $this->storeRaw('request', $requestPayload),
            'response_ref' => $responsePayload !== null ? $this->storeRaw('response', $responsePayload) : null,
            'prompt_tokens' => $usage?->promptTokens,
            'completion_tokens' => $usage?->completionTokens,
            'cached_tokens' => $usage?->cachedTokens,
            'total_tokens' => $usage?->totalTokens,
            'cost_micros' => $usage !== null ? $this->costMicros($model, $usage) : 0,
            'success' => $success,
            'error' => $error,
            'duration_ms' => $durationMs,
            'called_at' => $calledAt,
        ]);

        // Code Guidance P7.5 (D25): trace_id — без новой колонки, в контексте лога (Context
        // facade дегидрируется через request/job автоматически, research/J §1); коррелирует
        // конкретный `llm_calls`-ряд с HTTP-запросом/консольной командой/job'ом, его
        // породившими, БЕЗ изменения формата уже сохранённого сырого request/response.
        Log::info('LLM-вызов записан', [
            'llm_call_id' => $llmCall->id,
            'analysis_id' => $analysisId,
            'purpose' => $purpose->value,
            'provider' => $provider,
            'model' => $model,
            'success' => $success,
            'duration_ms' => $durationMs,
        ]);

        return $llmCall;
    }

    /**
     * `cost_micros = ceil(tokens × price_per_1M)` (эквивалент `× price_per_1M / 1e6 × 1e6`) —
     * билluemые prompt-токены исключают `cachedTokens` (`LlmUsage::promptTokens` — уже НЕ
     * включает кэш-хиты, см. докблок DTO), кэш-токены — по отдельному `cached_per_1m`-тарифу.
     * Модель отсутствует в `config('ai.capabilities')` (например, тестовый фейк-провайдер) —
     * cost_micros=0, не бросаем: учёт стоимости не должен ронять запись самого факта вызова.
     *
     * Индекс по модели БЕЗ dot-notation `config("ai.capabilities.{$model}")` (P7.2-фикс,
     * см. handoff.md/phases/P7.md Pending Work P6.1/Open risks P7.1) — имена моделей Gemini
     * содержат точки (`gemini-2.5-flash-lite`), `Arr::get()` дот-нотации трактует их как
     * разделители вложенности и НЕ находит запись → cost_micros молча оставался 0 для ЛЮБОЙ
     * реальной модели. `ExtractionPreflight` (P6.1) уже использует правильный индекс — тот же
     * приём здесь.
     */
    private function costMicros(string $model, LlmUsage $usage): int
    {
        $configuredCapabilities = config('ai.capabilities', []);
        $capabilities = is_array($configuredCapabilities) ? $configuredCapabilities : [];
        $pricing = $capabilities[$model] ?? null;

        if (! is_array($pricing)) {
            return 0;
        }

        // tokens × price_per_1M ≡ tokens × price_per_1M / 1e6 (доллары) × 1e6 (доллары → микро) — сокращается.
        $inputPrice = $this->number($pricing['input_per_1m'] ?? null);
        $cachedPrice = $this->number($pricing['cached_per_1m'] ?? null);
        $outputPrice = $this->number($pricing['output_per_1m'] ?? null);

        if ($inputPrice === null || $cachedPrice === null || $outputPrice === null) {
            return 0;
        }

        $micros = $usage->promptTokens * $inputPrice
            + $usage->cachedTokens * $cachedPrice
            + $usage->completionTokens * $outputPrice;

        return (int) ceil($micros);
    }

    private function storeRaw(string $kind, string $payload): string
    {
        $configuredDisk = config('crawler.raw_disk');
        $disk = is_string($configuredDisk) ? $configuredDisk : null;
        $path = sprintf('ai/llm-calls/%s/%s-%s.json', now()->format('Y/m/d'), $kind, (string) Str::uuid());

        Storage::disk($disk)->put($path, $payload);

        return $path;
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || is_string($value) ? (float) $value : null;
    }
}
