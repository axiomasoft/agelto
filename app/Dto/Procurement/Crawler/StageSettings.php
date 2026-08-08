<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/** Nullable fields explicitly mean «inherit the next settings layer». */
final class StageSettings extends Data
{
    public const array ALLOWED_KEYS = ['minPauseMs', 'maxPauseMs', 'retries', 'retryDelayMs', 'timeoutSeconds'];

    public function __construct(
        public ?int $minPauseMs = null,
        public ?int $maxPauseMs = null,
        public ?int $retries = null,
        public ?int $retryDelayMs = null,
        public ?int $timeoutSeconds = null,
    ) {}

    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $raw = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        $unknown = array_diff(array_keys($raw), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => sprintf('Неизвестный ключ настроек стадии: %s.', implode(', ', $unknown)),
            ]);
        }

        return parent::validateAndCreate($raw);
    }

    public static function rules(ValidationContext $context): array
    {
        return [
            'minPauseMs' => ['nullable', 'integer', 'min:0'],
            'maxPauseMs' => ['nullable', 'integer', 'gte:minPauseMs'],
            'retries' => ['nullable', 'integer', 'min:0'],
            'retryDelayMs' => ['nullable', 'integer', 'min:0'],
            'timeoutSeconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
