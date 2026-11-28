<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Правило нормализации строковых дат и времени в целевом часовом поясе.
 */
final readonly class DateParsingRule
{
    /**
     * @param  non-empty-list<string>  $formats
     */
    public function __construct(
        public array $formats,
        public string $timezone = 'Europe/Moscow',
        public ?RegexPattern $extractRegex = null,
    ) {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Invalid timezone "%s".', $timezone), 0, $e);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $formats = RuleData::stringList($data, 'formats');
        if ($formats === []) {
            throw new InvalidArgumentException('DateParsingRule requires non-empty formats list.');
        }

        return new self(
            formats: $formats,
            timezone: RuleData::string($data, 'timezone', 'Europe/Moscow'),
            extractRegex: RuleData::nullableRegex($data, 'extract_regex'),
        );
    }

    /**
     * @return array{
     *     formats: non-empty-list<string>,
     *     timezone: string,
     *     extract_regex: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'formats' => $this->formats,
            'timezone' => $this->timezone,
            'extract_regex' => $this->extractRegex?->pattern,
        ];
    }
}
