<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Primitives;

use App\Enums\Procurement\ProcurementEventType;
use InvalidArgumentException;

/**
 * Правило классификации записи журнала событий закупки в {@see ProcurementEventType}.
 */
final readonly class EventClassificationRule
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public ProcurementEventType $eventType,
        public array $keywords = [],
        public ?RegexPattern $pattern = null,
        public int $priority = 100,
    ) {
        if ($keywords === [] && $pattern === null) {
            throw new InvalidArgumentException('EventClassificationRule must define keywords or pattern.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawEventType = $data['event_type'] ?? null;
        if (! is_string($rawEventType)) {
            throw new InvalidArgumentException('EventClassificationRule requires string event_type.');
        }
        $eventType = ProcurementEventType::from($rawEventType);

        $rawKeywords = $data['keywords'] ?? [];
        if (! is_array($rawKeywords)) {
            throw new InvalidArgumentException('EventClassificationRule keywords must be an array.');
        }

        $keywords = [];
        foreach ($rawKeywords as $keyword) {
            if (! is_string($keyword) || trim($keyword) === '') {
                throw new InvalidArgumentException('EventClassificationRule keywords must be non-empty strings.');
            }
            $keywords[] = trim($keyword);
        }

        $rawPattern = $data['pattern'] ?? null;
        $pattern = null;
        if ($rawPattern !== null) {
            if (! is_string($rawPattern)) {
                throw new InvalidArgumentException('EventClassificationRule pattern must be a string or null.');
            }
            $pattern = new RegexPattern($rawPattern);
        }

        $rawPriority = $data['priority'] ?? 100;
        if (! is_int($rawPriority)) {
            throw new InvalidArgumentException('EventClassificationRule priority must be an integer.');
        }

        return new self(
            eventType: $eventType,
            keywords: $keywords,
            pattern: $pattern,
            priority: $rawPriority,
        );
    }

    /**
     * @return array{
     *     event_type: string,
     *     keywords: list<string>,
     *     pattern: string|null,
     *     priority: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType->value,
            'keywords' => $this->keywords,
            'pattern' => $this->pattern?->pattern,
            'priority' => $this->priority,
        ];
    }
}
