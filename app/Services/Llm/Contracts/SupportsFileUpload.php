<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Services\Llm\Dto\LlmFileHandle;

/**
 * Опциональный контракт Files API (ТЗ 04 §2.1, §2.3; research/E §3) — расширение против ТЗ:
 * PDF-документы не влезают inline в промт, Gemini Files API центральный путь их доставки.
 * `$mimeType` — ОБЯЗАТЕЛЬНЫЙ параметр (v1-баг: дефолтный `application/pdf` вместо реального MIME,
 * research/C §5) — вызывающий код обязан передавать реальный MIME файла, не угадывать его тут.
 */
interface SupportsFileUpload
{
    public function uploadFile(string $path, string $mimeType): LlmFileHandle;
}
