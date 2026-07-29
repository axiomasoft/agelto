<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\LlmCall;

/**
 * Единственная точка персистентности `llm_calls` — `LlmCallRecorder` решает, какие
 * атрибуты писать (usage, cost_micros, request/response refs), репозиторий только пишет
 * (аналог `DocumentStoreRepository`).
 */
final readonly class LlmCallStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LlmCall
    {
        return LlmCall::query()->create($attributes);
    }
}
