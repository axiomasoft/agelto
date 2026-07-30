<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\Llm\Dto\StoredPayload;
use Illuminate\Support\Facades\Storage;

/**
 * Чтение сохранённого request/response payload'а LLM-вызова по ссылке `llm_calls.request_ref`/
 * `response_ref` для витрины финального промта (P5.5, D46) — показывается то, что РЕАЛЬНО ушло
 * в провайдера, композитор промтов здесь не вызывается. Единственный читатель `crawler.raw_disk`
 * для этой витрины: прямой `Storage::disk()` из страницы/компонента запрещён (Implementation
 * Rule 4 P5.5) — экран ходит СЮДА, а не в диск напрямую.
 */
final readonly class LlmPayloadReader
{
    /**
     * Лимит чтения (Implementation Rule 7 P5.5): большой payload не грузится в память целиком,
     * факт усечения показывается явно, а не молчит.
     */
    private const MAX_BYTES = 262_144;

    public function read(?string $ref): StoredPayload
    {
        if ($ref === null) {
            return StoredPayload::absent();
        }

        $disk = Storage::disk((string) config('crawler.raw_disk'));

        if (! $disk->exists($ref)) {
            return StoredPayload::expired($this->expireDays());
        }

        $stream = $disk->readStream($ref);

        if ($stream === null) {
            return StoredPayload::expired($this->expireDays());
        }

        $content = stream_get_contents($stream, self::MAX_BYTES);
        $truncated = ! feof($stream);
        fclose($stream);

        return StoredPayload::content($content !== false ? $content : '', $truncated);
    }

    private function expireDays(): int
    {
        return (int) config('partitioning.minio_lifecycle.llm_call_blobs.expire_days', 90);
    }
}
