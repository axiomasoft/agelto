<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\LlmCall;

/**
 * Единственная точка персистентности `prc_ai_llm_calls` — `LlmCallRecorder` решает, какие
 * атрибуты писать (usage, cost_micros, request/response refs), репозиторий только пишет
 * (аналог `DocumentStoreRepository`).
 */
final readonly class AiCallStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LlmCall
    {
        return LlmCall::query()->create($attributes);
    }
}
