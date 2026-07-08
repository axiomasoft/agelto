<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonImmutable;

/**
 * Ссылка на файл, загруженный провайдеру через Files API (Gemini: 48ч TTL, research/E §3).
 * `uri` — provider-специфичный идентификатор (`files/xxx`), домен его не парсит.
 */
final readonly class LlmFileHandle
{
    public function __construct(
        public string $uri,
        public CarbonImmutable $expiresAt,
    ) {}
}
