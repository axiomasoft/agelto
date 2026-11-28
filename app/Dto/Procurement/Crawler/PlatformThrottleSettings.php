<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\CrawlStage;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/** Platform-wide request budget and stage defaults; profile-less requests use this layer. */
final class PlatformThrottleSettings extends Data
{
    public const array ALLOWED_KEYS = ['requestsPerMinuteBase', 'requestsPerMinutePerProxy', 'maxWaitSeconds', 'stages'];

    public function __construct(
        public ?int $requestsPerMinuteBase = null,
        /** @deprecated Историческое поле читается для совместимости; origin quota от числа маршрутов не зависит. */
        public ?int $requestsPerMinutePerProxy = null,
        public ?int $maxWaitSeconds = null,
        /** @var array<string, array<string, mixed>> */
        public array $stages = [],
    ) {}

    /**
     * @param  Arrayable<array-key, mixed>|array<string, mixed>  $payload
     */
    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $raw = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        $unknown = array_diff(array_keys($raw), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'throttle' => sprintf('Неизвестный ключ настроек площадки: %s.', implode(', ', $unknown)),
            ]);
        }

        $stages = $raw['stages'] ?? [];

        if (! is_array($stages)) {
            throw ValidationException::withMessages(['throttle.stages' => 'Настройки стадий должны быть объектом.']);
        }

        foreach ($stages as $stage => $settings) {
            if (CrawlStage::tryFrom((string) $stage) === null) {
                throw ValidationException::withMessages(['throttle.stages' => "Неизвестная стадия: {$stage}."]);
            }

            if (! is_array($settings)) {
                throw ValidationException::withMessages(["throttle.stages.{$stage}" => 'Настройки стадии должны быть объектом.']);
            }

            /** @var array<string, mixed> $settings */
            $stages[$stage] = StageSettings::validateAndCreate($settings)->toArray();
        }

        $raw['stages'] = $stages;

        return parent::validateAndCreate($raw);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'requestsPerMinuteBase' => ['nullable', 'integer', 'min:0'],
            'requestsPerMinutePerProxy' => ['nullable', 'integer', 'min:0'],
            'maxWaitSeconds' => ['nullable', 'integer', 'min:0'],
            'stages' => ['array'],
        ];
    }

    public function forStage(CrawlStage $stage): ?StageSettings
    {
        $settings = $this->stages[$stage->value] ?? null;

        return is_array($settings) ? StageSettings::validateAndCreate($settings) : null;
    }
}
