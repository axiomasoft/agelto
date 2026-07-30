<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Итог чтения сырого request/response payload'а LLM-вызова с raw-диска (P5.5, D46). Три штатных
 * состояния, ни одно из которых не ошибка: ссылки нет (`request_ref`/`response_ref === null`),
 * блоб истёк по MinIO ILM (ссылка есть, файла нет — `config('partitioning.minio_lifecycle')`),
 * содержимое доступно (возможно усечённое лимитом чтения, Implementation Rule 7 P5.5).
 */
final readonly class StoredPayload
{
    private function __construct(
        public bool $available,
        public ?string $content,
        public ?string $unavailableReason,
        public bool $truncated,
    ) {}

    public static function absent(): self
    {
        return new self(available: false, content: null, unavailableReason: 'Ссылки на payload нет.', truncated: false);
    }

    public static function expired(int $expireDays): self
    {
        return new self(
            available: false,
            content: null,
            unavailableReason: "Payload удалён политикой хранения (срок — {$expireDays} дн.).",
            truncated: false,
        );
    }

    public static function content(string $content, bool $truncated): self
    {
        return new self(available: true, content: $content, unavailableReason: null, truncated: $truncated);
    }
}
