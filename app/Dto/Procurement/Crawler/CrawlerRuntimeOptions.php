<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/** Profile-local listing overrides; legacy CSV options remain part of the typed contract. */
final class CrawlerRuntimeOptions extends Data
{
    public const array ALLOWED_KEYS = ['columns', 'records_per_page', 'retries', 'retryDelayMs', 'minPauseMs', 'maxPauseMs'];

    public function __construct(
        /** @var array<string, bool>|null */
        public ?array $columns = null,
        public ?int $recordsPerPage = null,
        public ?int $retries = null,
        public ?int $retryDelayMs = null,
        public ?int $minPauseMs = null,
        public ?int $maxPauseMs = null,
    ) {}

    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $raw = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        $unknown = array_diff(array_keys($raw), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'options' => sprintf('Неизвестный ключ настроек профиля: %s.', implode(', ', $unknown)),
            ]);
        }

        if (array_key_exists('records_per_page', $raw)) {
            $raw['recordsPerPage'] = $raw['records_per_page'];
            unset($raw['records_per_page']);
        }

        return parent::validateAndCreate($raw);
    }

    public static function rules(ValidationContext $context): array
    {
        return [
            'columns' => ['nullable', 'array'],
            'columns.*' => ['boolean'],
            'recordsPerPage' => ['nullable', 'integer', 'min:1'],
            'retries' => ['nullable', 'integer', 'min:0'],
            'retryDelayMs' => ['nullable', 'integer', 'min:0'],
            'minPauseMs' => ['nullable', 'integer', 'min:0'],
            'maxPauseMs' => ['nullable', 'integer', 'gte:minPauseMs'],
        ];
    }

    public function listingOverrides(): StageSettings
    {
        return new StageSettings(
            minPauseMs: $this->minPauseMs,
            maxPauseMs: $this->maxPauseMs,
            retries: $this->retries,
            retryDelayMs: $this->retryDelayMs,
        );
    }
}
